<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

final class SslCertificateService
{
    private const STALE_RUNNING_TASK_SECONDS = 3600;

    public function __construct(
        private Config $config,
        private Database $db,
    ) {
    }

    /**
     * @return array{task_id:int,created:bool,status:string}
     */
    public function queueTask(int $certificateId, string $taskType = 'issue', ?int $requestedByUserId = null): array
    {
        $this->expireStaleRunningTasks();

        $existing = $this->db->fetchOne(
            'SELECT id, status
             FROM ssl_certificate_tasks
             WHERE certificate_id = :certificate_id
               AND (status = :pending OR status = :running)
             ORDER BY id DESC
             LIMIT 1',
            [
                'certificate_id' => $certificateId,
                'pending' => 'pending',
                'running' => 'running',
            ]
        );

        if (is_array($existing)) {
            return [
                'task_id' => (int) $existing['id'],
                'created' => false,
                'status' => (string) $existing['status'],
            ];
        }

        $now = gmdate('Y-m-d H:i:s');
        $taskId = $this->db->insert('ssl_certificate_tasks', [
            'certificate_id' => $certificateId,
            'task_type' => $taskType,
            'status' => 'pending',
            'requested_by_user_id' => $requestedByUserId,
            'output_log' => null,
            'error_message' => null,
            'started_at' => null,
            'finished_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'task_id' => $taskId,
            'created' => true,
            'status' => 'pending',
        ];
    }

    public function cancelPendingTasks(int $certificateId, string $reason): int
    {
        $now = gmdate('Y-m-d H:i:s');
        return $this->db->execute(
            'UPDATE ssl_certificate_tasks
             SET status = :status,
                 error_message = :error_message,
                 finished_at = :finished_at,
                 updated_at = :updated_at
             WHERE certificate_id = :certificate_id
               AND status = :pending',
            [
                'status' => 'failed',
                'error_message' => $reason,
                'finished_at' => $now,
                'updated_at' => $now,
                'certificate_id' => $certificateId,
                'pending' => 'pending',
            ]
        );
    }

    /**
     * @return array{processed:int,completed:int,failed:int}
     */
    public function processPendingTasks(int $limit = 5): array
    {
        $this->expireStaleRunningTasks();

        $summary = [
            'processed' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        $limit = max(1, $limit);
        for ($index = 0; $index < $limit; $index++) {
            $task = $this->claimNextPendingTask();
            if (!is_array($task)) {
                break;
            }

            $summary['processed']++;
            if ($this->runTask($task)) {
                $summary['completed']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /**
     * @return array<int, string>
     */
    public function requestedDomains(string $domain, bool $wildcard): array
    {
        $normalized = $this->normalizeCertificateDomain($domain);
        if ($normalized === '') {
            return [];
        }
        if (!$wildcard) {
            return [$normalized];
        }

        $baseDomain = $this->certificateDirectoryDomain($normalized, true);
        return ['*.' . $baseDomain, $baseDomain];
    }

    public function certificateDirectoryDomain(string $domain, bool $wildcard): string
    {
        $normalized = $this->normalizeCertificateDomain($domain);
        return $wildcard ? $normalized : $normalized;
    }

    /**
     * @return array{0:string,1:string}
     */
    public function defaultPanelCertbotPaths(string $certificateName): array
    {
        $dirs = $this->panelCertbotDirectories();
        $name = $this->normalizeCertificateDomain($certificateName);

        return [
            $dirs['config'] . DIRECTORY_SEPARATOR . 'live' . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'fullchain.pem',
            $dirs['config'] . DIRECTORY_SEPARATOR . 'live' . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'privkey.pem',
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    public function readPanelCertificateFiles(string $certPath, string $keyPath): array
    {
        if (!is_file($certPath) || !is_readable($certPath)) {
            throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'panel certificate file is not readable: ' . $certPath]);
        }
        if (!is_file($keyPath) || !is_readable($keyPath)) {
            throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'panel private key file is not readable: ' . $keyPath]);
        }

        $certPem = trim((string) file_get_contents($certPath));
        $keyPem = trim((string) file_get_contents($keyPath));
        if ($certPem === '' || $keyPem === '') {
            throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'panel certificate or private key file is empty']);
        }

        $this->validateTlsPair($certPem, $keyPem);
        return [$certPem, $keyPem];
    }

    /**
     * @return array{domains: array<int, string>, issuer_name: ?string, expires_at: ?string}
     */
    public function parseCertificateMetadata(string $certPem): array
    {
        if (!function_exists('openssl_x509_read') || !function_exists('openssl_x509_parse')) {
            throw new ApiException('openssl_unavailable', 500, ['message' => 'OpenSSL support is required to parse SSL certificate metadata']);
        }

        $certificate = @openssl_x509_read($certPem);
        if ($certificate === false) {
            throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'certificate PEM could not be parsed']);
        }

        $parsed = @openssl_x509_parse($certificate);
        if (!is_array($parsed)) {
            throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'certificate metadata could not be parsed']);
        }

        $domains = [];
        $subjectCommonName = trim((string) (($parsed['subject']['CN'] ?? '') ?: ''));
        if ($subjectCommonName !== '') {
            $domains[] = $subjectCommonName;
        }

        $subjectAltName = (string) (($parsed['extensions']['subjectAltName'] ?? '') ?: '');
        if ($subjectAltName !== '' && preg_match_all('/DNS:([^,\s]+)/', $subjectAltName, $matches)) {
            foreach ($matches[1] as $match) {
                $value = trim((string) $match);
                if ($value !== '') {
                    $domains[] = $value;
                }
            }
        }
        $domains = array_values(array_unique($domains));

        $issuerParts = is_array($parsed['issuer'] ?? null) ? $parsed['issuer'] : [];
        $issuerNameParts = array_values(array_filter([
            trim((string) ($issuerParts['O'] ?? '')),
            trim((string) ($issuerParts['OU'] ?? '')),
            trim((string) ($issuerParts['CN'] ?? '')),
        ], static fn(string $value): bool => $value !== ''));
        $issuerName = $issuerNameParts !== [] ? implode(' / ', array_unique($issuerNameParts)) : null;

        $expiresAt = null;
        if (isset($parsed['validTo_time_t'])) {
            $expiresAt = gmdate('Y-m-d H:i:s', (int) $parsed['validTo_time_t']);
        }

        return [
            'domains' => $domains,
            'issuer_name' => $issuerName,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claimNextPendingTask(): ?array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $task = $this->db->fetchOne(
                'SELECT *
                 FROM ssl_certificate_tasks
                 WHERE status = :status
                 ORDER BY id ASC
                 LIMIT 1',
                ['status' => 'pending']
            );

            if (!is_array($task)) {
                return null;
            }

            $now = gmdate('Y-m-d H:i:s');
            $updated = $this->db->execute(
                'UPDATE ssl_certificate_tasks
                 SET status = :running,
                     started_at = :started_at,
                     finished_at = NULL,
                     output_log = NULL,
                     error_message = NULL,
                     updated_at = :updated_at
                 WHERE id = :id
                   AND status = :pending',
                [
                    'running' => 'running',
                    'started_at' => $now,
                    'updated_at' => $now,
                    'id' => $task['id'],
                    'pending' => 'pending',
                ]
            );

            if ($updated === 1) {
                return $this->db->fetchOne('SELECT * FROM ssl_certificate_tasks WHERE id = :id LIMIT 1', [
                    'id' => $task['id'],
                ]);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function runTask(array $task): bool
    {
        $logs = [];

        try {
            $certificate = $this->db->fetchOne('SELECT * FROM ssl_certificates WHERE id = :id LIMIT 1', [
                'id' => $task['certificate_id'],
            ]);
            if (!is_array($certificate)) {
                throw new ApiException('ssl_certificate_not_found', 404, ['message' => 'SSL certificate record was removed before the queued task ran.']);
            }
            if ((string) ($certificate['source_type'] ?? '') !== 'panel_letsencrypt') {
                throw new ApiException('invalid_ssl_certificate_task', 422, ['message' => 'Queued task aborted because this certificate no longer uses panel Let\'s Encrypt.']);
            }

            $sourceConfig = $this->decodeJsonObject($certificate['source_config_json'] ?? null);
            $domain = trim((string) ($sourceConfig['domain'] ?? ''));
            $acmeEmail = trim((string) ($sourceConfig['email'] ?? ''));
            $wildcard = !empty($sourceConfig['wildcard']);
            $credentialProfileId = (int) ($sourceConfig['credential_profile_id'] ?? 0);
            $dnsPropagationSeconds = max(10, (int) ($sourceConfig['dns_propagation_seconds'] ?? 60));

            if ($domain === '') {
                throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'panel-hosted Let\'s Encrypt source requires a primary domain']);
            }
            if ($acmeEmail === '') {
                throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'panel-hosted Let\'s Encrypt source requires an ACME email']);
            }
            if ($credentialProfileId <= 0) {
                throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'panel-hosted Let\'s Encrypt via CF DNS requires a Cloudflare DNS credential profile']);
            }

            $credentialProfile = $this->db->fetchOne('SELECT * FROM credential_profiles WHERE id = :id LIMIT 1', [
                'id' => $credentialProfileId,
            ]);
            if (!is_array($credentialProfile)) {
                throw new ApiException('credential_profile_not_found', 404, ['message' => 'The selected DNS credential profile was not found.']);
            }
            if ((string) ($credentialProfile['provider'] ?? '') !== 'cloudflare_global_key') {
                throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'selected DNS credential profile must use Cloudflare Global Key']);
            }

            $certificateName = trim((string) ($sourceConfig['certificate_name'] ?? ''));
            if ($certificateName === '') {
                $certificateName = $this->certificateDirectoryDomain($domain, $wildcard);
            }
            [$defaultCertPath, $defaultKeyPath] = $this->defaultPanelCertbotPaths($certificateName);
            $certPath = trim((string) ($sourceConfig['cert_path'] ?? ''));
            $keyPath = trim((string) ($sourceConfig['key_path'] ?? ''));
            if ($certPath === '') {
                $certPath = $defaultCertPath;
            }
            if ($keyPath === '') {
                $keyPath = $defaultKeyPath;
            }
            $taskConfigSnapshot = [
                'domain' => $domain,
                'email' => $acmeEmail,
                'wildcard' => $wildcard ? 1 : 0,
                'credential_profile_id' => $credentialProfileId,
                'dns_propagation_seconds' => $dnsPropagationSeconds,
                'certificate_name' => $certificateName,
                'cert_path' => $certPath,
                'key_path' => $keyPath,
            ];

            $result = $this->issuePanelLetsEncryptCertificate(
                $domain,
                $acmeEmail,
                $wildcard,
                $credentialProfile,
                $certificateName,
                $dnsPropagationSeconds
            );
            $logs[] = $this->formatCommandResult($result);

            if ($result['exit_code'] !== 0) {
                $message = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
                if ($message === '') {
                    $message = 'certbot returned a non-zero exit code';
                }
                throw new ApiException('ssl_certificate_issue_failed', 422, [
                    'message' => $this->normalizeCertbotIssueError($message),
                ]);
            }

            [$certPem] = $this->readPanelCertificateFiles($certPath, $keyPath);
            $metadata = $this->parseCertificateMetadata($certPem);

            $latestCertificate = $this->db->fetchOne('SELECT * FROM ssl_certificates WHERE id = :id LIMIT 1', [
                'id' => $certificate['id'],
            ]);
            if (!is_array($latestCertificate)) {
                throw new ApiException('ssl_certificate_not_found', 404, ['message' => 'SSL certificate record was removed before the queued task could save its result.']);
            }
            if ((string) ($latestCertificate['source_type'] ?? '') !== 'panel_letsencrypt') {
                throw new ApiException('invalid_ssl_certificate_task', 422, ['message' => 'Queued task finished, but the certificate source changed while it was running. Result was not applied.']);
            }
            $latestSourceConfig = $this->decodeJsonObject($latestCertificate['source_config_json'] ?? null);
            if (!$this->panelLetsEncryptConfigMatches($latestSourceConfig, $taskConfigSnapshot)) {
                throw new ApiException('invalid_ssl_certificate_task', 422, ['message' => 'Queued task finished, but the certificate settings changed while it was running. Save again to queue a fresh SSL task.']);
            }

            $updatedSourceConfig = $sourceConfig;
            $updatedSourceConfig['domain'] = $domain;
            $updatedSourceConfig['email'] = $acmeEmail;
            $updatedSourceConfig['wildcard'] = $wildcard ? 1 : 0;
            $updatedSourceConfig['credential_profile_id'] = $credentialProfileId;
            $updatedSourceConfig['dns_propagation_seconds'] = $dnsPropagationSeconds;
            $updatedSourceConfig['certificate_name'] = $certificateName;
            $updatedSourceConfig['cert_path'] = $certPath;
            $updatedSourceConfig['key_path'] = $keyPath;

            $now = gmdate('Y-m-d H:i:s');
            $this->db->execute(
                'UPDATE ssl_certificates
                 SET domains_json = :domains_json,
                     issuer_name = :issuer_name,
                     expires_at = :expires_at,
                     renewal_mode = :renewal_mode,
                     source_config_json = :source_config_json,
                     cert_pem = NULL,
                     key_pem = NULL,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'domains_json' => json_encode($metadata['domains'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'issuer_name' => $metadata['issuer_name'],
                    'expires_at' => $metadata['expires_at'],
                    'renewal_mode' => 'panel_certbot',
                    'source_config_json' => json_encode($updatedSourceConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                    'id' => $certificate['id'],
                ]
            );

            $logs[] = 'Certificate files synchronized from:';
            $logs[] = 'cert_path=' . $certPath;
            $logs[] = 'key_path=' . $keyPath;
            $logs[] = 'domains=' . implode(', ', $metadata['domains']);
            if ($metadata['expires_at'] !== null) {
                $logs[] = 'expires_at=' . $metadata['expires_at'];
            }

            $this->finishTask((int) $task['id'], 'completed', $this->joinLogSections($logs), null);
            return true;
        } catch (\Throwable $throwable) {
            $details = $throwable instanceof ApiException ? $throwable->details() : [];
            $detailsLog = trim((string) ($details['output_log'] ?? ''));
            if ($detailsLog !== '') {
                $logs[] = $detailsLog;
            }

            $errorMessage = $this->displayThrowableMessage($throwable);
            if ($errorMessage !== '' && $logs === []) {
                $logs[] = $errorMessage;
            }

            $this->finishTask(
                (int) $task['id'],
                'failed',
                $this->joinLogSections($logs),
                $errorMessage !== '' ? $errorMessage : 'SSL task failed.'
            );
            return false;
        }
    }

    private function finishTask(int $taskId, string $status, ?string $outputLog, ?string $errorMessage): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->execute(
            'UPDATE ssl_certificate_tasks
             SET status = :status,
                 output_log = :output_log,
                 error_message = :error_message,
                 finished_at = :finished_at,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'status' => $status,
                'output_log' => $outputLog !== '' ? $outputLog : null,
                'error_message' => $errorMessage !== '' ? $errorMessage : null,
                'finished_at' => $now,
                'updated_at' => $now,
                'id' => $taskId,
            ]
        );
    }

    private function expireStaleRunningTasks(): void
    {
        $threshold = gmdate('Y-m-d H:i:s', time() - self::STALE_RUNNING_TASK_SECONDS);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->execute(
            'UPDATE ssl_certificate_tasks
             SET status = :failed,
                 error_message = :error_message,
                 finished_at = :finished_at,
                 updated_at = :updated_at
             WHERE status = :running
               AND started_at IS NOT NULL
               AND started_at < :threshold',
            [
                'failed' => 'failed',
                'error_message' => 'The backend worker timed out or exited unexpectedly before this SSL task finished.',
                'finished_at' => $now,
                'updated_at' => $now,
                'running' => 'running',
                'threshold' => $threshold,
            ]
        );
    }

    /**
     * @param array<string, mixed> $credentialProfile
     * @return array{command:string,exit_code:int,stdout:string,stderr:string}
     */
    private function issuePanelLetsEncryptCertificate(
        string $domain,
        string $acmeEmail,
        bool $wildcard,
        array $credentialProfile,
        string $certificateName,
        int $dnsPropagationSeconds
    ): array {
        $credentialConfig = $this->decodeJsonObject($credentialProfile['config_json'] ?? null);
        $cloudflareEmail = trim((string) ($credentialConfig['cloudflare_email'] ?? ''));
        $cloudflareGlobalKey = trim((string) ($credentialConfig['cloudflare_global_key'] ?? ''));
        if ($cloudflareEmail === '' || $cloudflareGlobalKey === '') {
            throw new ApiException('credential_profile_invalid', 422, ['message' => 'selected Cloudflare DNS credential profile is missing email or global key']);
        }

        $credentialFile = tempnam(sys_get_temp_dir(), 'leikwan-cf-');
        if ($credentialFile === false) {
            throw new ApiException('ssl_certificate_issue_failed', 500, ['message' => 'failed to allocate temporary Cloudflare credential file']);
        }

        try {
            $content = "dns_cloudflare_email = {$cloudflareEmail}\n"
                . "dns_cloudflare_api_key = {$cloudflareGlobalKey}\n";
            file_put_contents($credentialFile, $content);
            @chmod($credentialFile, 0600);

            $certbotDirs = $this->panelCertbotDirectories();
            $parts = [
                'certbot',
                'certonly',
                '--dns-cloudflare',
                '--dns-cloudflare-credentials',
                $credentialFile,
                '--dns-cloudflare-propagation-seconds',
                (string) max(10, $dnsPropagationSeconds),
                '--config-dir',
                $certbotDirs['config'],
                '--work-dir',
                $certbotDirs['work'],
                '--logs-dir',
                $certbotDirs['logs'],
                '--cert-name',
                $certificateName,
                '--non-interactive',
                '--agree-tos',
                '--keep-until-expiring',
                '-m',
                $acmeEmail,
            ];
            foreach ($this->requestedDomains($domain, $wildcard) as $requestedDomain) {
                $parts[] = '-d';
                $parts[] = $requestedDomain;
            }

            [$exitCode, $stdout, $stderr, $command] = $this->runLocalCommand($parts);
            return [
                'command' => $command,
                'exit_code' => $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        } finally {
            if (is_file($credentialFile)) {
                @unlink($credentialFile);
            }
        }
    }

    /**
     * @return array{config:string,work:string,logs:string}
     */
    private function panelCertbotDirectories(): array
    {
        $masterRoot = dirname(__DIR__, 2);
        $baseDir = rtrim((string) ($this->config->get('PANEL_CERTBOT_BASE_DIR', $masterRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'certbot') ?? ''), DIRECTORY_SEPARATOR);
        $configDir = (string) ($this->config->get('PANEL_CERTBOT_CONFIG_DIR', $baseDir . DIRECTORY_SEPARATOR . 'config') ?? '');
        $workDir = (string) ($this->config->get('PANEL_CERTBOT_WORK_DIR', $baseDir . DIRECTORY_SEPARATOR . 'work') ?? '');
        $logsDir = (string) ($this->config->get('PANEL_CERTBOT_LOGS_DIR', $baseDir . DIRECTORY_SEPARATOR . 'logs') ?? '');

        foreach ([$configDir, $workDir, $logsDir] as $path) {
            if ($path === '') {
                throw new ApiException('ssl_certificate_issue_failed', 500, ['message' => 'panel certbot directories are not configured']);
            }
            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                throw new ApiException('ssl_certificate_issue_failed', 500, ['message' => 'failed to create writable panel certbot directory: ' . $path]);
            }
            if (!is_writable($path)) {
                throw new ApiException('ssl_certificate_issue_failed', 500, ['message' => 'panel certbot directory is not writable: ' . $path]);
            }
        }

        return [
            'config' => $configDir,
            'work' => $workDir,
            'logs' => $logsDir,
        ];
    }

    private function normalizeCertificateDomain(string $domain): string
    {
        $normalized = strtolower(trim($domain));
        if ($normalized === '') {
            return '';
        }

        return str_starts_with($normalized, '*.') ? substr($normalized, 2) : $normalized;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $expected
     */
    private function panelLetsEncryptConfigMatches(array $current, array $expected): bool
    {
        return strtolower(trim((string) ($current['domain'] ?? ''))) === strtolower(trim((string) ($expected['domain'] ?? '')))
            && strtolower(trim((string) ($current['email'] ?? ''))) === strtolower(trim((string) ($expected['email'] ?? '')))
            && !empty($current['wildcard']) === !empty($expected['wildcard'])
            && (int) ($current['credential_profile_id'] ?? 0) === (int) ($expected['credential_profile_id'] ?? 0)
            && max(10, (int) ($current['dns_propagation_seconds'] ?? 60)) === max(10, (int) ($expected['dns_propagation_seconds'] ?? 60))
            && trim((string) ($current['certificate_name'] ?? '')) === trim((string) ($expected['certificate_name'] ?? ''))
            && trim((string) ($current['cert_path'] ?? '')) === trim((string) ($expected['cert_path'] ?? ''))
            && trim((string) ($current['key_path'] ?? '')) === trim((string) ($expected['key_path'] ?? ''));
    }

    private function normalizeCertbotIssueError(string $message): string
    {
        $normalized = strtolower($message);
        if (
            str_contains($normalized, '--dns-cloudflare')
            || str_contains($normalized, '--dns-cloudflare-credentials')
            || str_contains($normalized, 'unrecognized arguments')
        ) {
            return 'The panel host certbot is missing the Cloudflare DNS plugin. Install `python3-certbot-dns-cloudflare` on the panel host, then try again.';
        }
        if (str_contains($normalized, 'certbot: not found') || str_contains($normalized, 'command not found')) {
            return 'The panel host does not have certbot installed. Install `certbot` and `python3-certbot-dns-cloudflare`, then try again.';
        }
        if (str_contains($normalized, 'challenge failed for domain') || str_contains($normalized, 'dns-01 challenge')) {
            return 'Cloudflare DNS validation failed. Usually this means the TXT record did not propagate in time, or the selected Cloudflare credential cannot manage the correct zone. Increase `DNS Propagation Seconds` to 60 or 120 and verify the domain is in the same Cloudflare account as the selected credential.';
        }
        return $message;
    }

    private function displayThrowableMessage(\Throwable $throwable): string
    {
        if ($throwable instanceof ApiException) {
            $details = $throwable->details();
            $message = trim((string) ($details['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        return $throwable->getMessage();
    }

    /**
     * @param array<int, string> $parts
     * @param array<string, string> $env
     * @return array{0:int,1:string,2:string,3:string}
     */
    private function runLocalCommand(array $parts, array $env = []): array
    {
        if (!function_exists('proc_open')) {
            throw new ApiException('proc_open_unavailable', 500);
        }

        $escaped = array_map(static fn(string $part): string => escapeshellarg($part), $parts);
        $command = implode(' ', $escaped);
        $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $processEnv = null;
        if ($env !== []) {
            $processEnv = getenv();
            if (!is_array($processEnv)) {
                $processEnv = [];
            }
            foreach ($env as $key => $value) {
                $processEnv[(string) $key] = (string) $value;
            }
        }

        $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__, 3), $processEnv);
        if (!is_resource($process)) {
            throw new ApiException('proc_open_failed', 500);
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, $stdout, $stderr, $command];
    }

    private function validateTlsPair(string $certPem, string $keyPem): void
    {
        if (!str_contains($certPem, 'BEGIN CERTIFICATE')) {
            throw new ApiException('invalid_uploaded_tls_cert', 422, ['message' => 'certificate PEM is invalid or missing BEGIN CERTIFICATE']);
        }
        if (!str_contains($keyPem, 'BEGIN ') || !str_contains($keyPem, 'PRIVATE KEY')) {
            throw new ApiException('invalid_uploaded_tls_key', 422, ['message' => 'private key PEM is invalid or missing BEGIN PRIVATE KEY']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array{command:string,exit_code:int,stdout:string,stderr:string} $result
     */
    private function formatCommandResult(array $result): string
    {
        $sections = [
            '$ ' . $result['command'],
            'exit_code=' . $result['exit_code'],
        ];

        $stdout = trim($result['stdout']);
        if ($stdout !== '') {
            $sections[] = 'stdout:' . PHP_EOL . $stdout;
        }

        $stderr = trim($result['stderr']);
        if ($stderr !== '') {
            $sections[] = 'stderr:' . PHP_EOL . $stderr;
        }

        return implode(PHP_EOL . PHP_EOL, $sections);
    }

    /**
     * @param array<int, string> $sections
     */
    private function joinLogSections(array $sections): ?string
    {
        $clean = array_values(array_filter(array_map(static fn(string $value): string => trim($value), $sections), static fn(string $value): bool => $value !== ''));
        if ($clean === []) {
            return null;
        }

        return implode(PHP_EOL . PHP_EOL, $clean);
    }
}
