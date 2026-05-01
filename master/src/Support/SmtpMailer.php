<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;

final class SmtpMailer
{
    public function __construct(
        private Config $config,
    ) {
    }

    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): void
    {
        $host = trim((string) ($this->config->get('SMTP_HOST', '') ?? ''));
        if ($host === '') {
            throw new \RuntimeException('SMTP_HOST is not configured.');
        }

        $port = max(1, (int) ($this->config->getInt('SMTP_PORT', 587) ?? 587));
        $encryption = strtolower(trim((string) ($this->config->get('SMTP_ENCRYPTION', 'tls') ?? 'tls')));
        $username = trim((string) ($this->config->get('SMTP_USERNAME', '') ?? ''));
        $password = (string) ($this->config->get('SMTP_PASSWORD', '') ?? '');
        $fromEmail = trim((string) ($this->config->get('SMTP_FROM_EMAIL', '') ?? ''));
        $fromName = trim((string) ($this->config->get('SMTP_FROM_NAME', '') ?? ''));
        $heloHost = trim((string) ($this->config->get('SMTP_HELO_HOST', parse_url((string) ($this->config->get('APP_URL', 'localhost') ?? 'localhost'), PHP_URL_HOST) ?: 'localhost') ?? 'localhost'));

        if ($fromEmail === '') {
            throw new \RuntimeException('SMTP_FROM_EMAIL is not configured.');
        }

        if ($textBody === '') {
            $textBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));
        }

        $socketTarget = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($socketTarget, $errno, $errstr, 15);
        if (!is_resource($socket)) {
            throw new \RuntimeException('SMTP connection failed: ' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($socket, 15);

        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO ' . $heloHost, [250]);

            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                $cryptoEnabled = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($cryptoEnabled !== true) {
                    throw new \RuntimeException('Failed to start TLS for SMTP connection.');
                }
                $this->command($socket, 'EHLO ' . $heloHost, [250]);
            }

            if ($username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($username), [334]);
                $this->command($socket, base64_encode($password), [235]);
            }

            $this->command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $boundary = '=_leikwan_' . bin2hex(random_bytes(12));
            $message = $this->buildMessage(
                $fromEmail,
                $fromName !== '' ? $fromName : (string) ($this->config->get('APP_NAME', '利群會聚 LeiKwan Bridge') ?? '利群會聚 LeiKwan Bridge'),
                $toEmail,
                $toName,
                $subject,
                $boundary,
                $textBody,
                $htmlBody
            );

            fwrite($socket, $message . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private function buildMessage(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $toName,
        string $subject,
        string $boundary,
        string $textBody,
        string $htmlBody,
    ): string {
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s O'),
            'From: ' . $this->formatAddress($fromEmail, $fromName),
            'To: ' . $this->formatAddress($toEmail, $toName),
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $body = '--' . $boundary . "\r\n"
            . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
            . chunk_split(base64_encode($textBody), 76, "\r\n")
            . '--' . $boundary . "\r\n"
            . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
            . chunk_split(base64_encode($htmlBody), 76, "\r\n")
            . '--' . $boundary . '--';

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function formatAddress(string $email, string $name): string
    {
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            return '<' . $email . '>';
        }

        return $this->encodeHeader($trimmedName) . ' <' . $email . '>';
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /**
     * @param array<int, int> $expectedCodes
     */
    private function command($socket, string $command, array $expectedCodes): void
    {
        fwrite($socket, $command . "\r\n");
        $this->expect($socket, $expectedCodes);
    }

    /**
     * @param array<int, int> $expectedCodes
     */
    private function expect($socket, array $expectedCodes): void
    {
        $response = $this->readResponse($socket);
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new \RuntimeException('SMTP server rejected request: ' . trim($response));
        }
    }

    private function readResponse($socket): string
    {
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) {
                break;
            }

            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        return $response;
    }
}
