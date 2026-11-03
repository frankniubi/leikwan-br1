<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;

final class WhmcsClient
{
    private Config $config;

    /**
     * @var callable(string, array<string, mixed>): array<string, mixed>|null
     */
    private $requestHandler;

    /**
     * @param callable(string, array<string, mixed>): array<string, mixed>|null $requestHandler
     */
    public function __construct(Config $config, ?callable $requestHandler = null)
    {
        $this->config = $config;
        $this->requestHandler = $requestHandler;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function request(string $action, array $params = []): array
    {
        $baseUrl = rtrim((string) $this->config->get('WHMCS_BASE_URL', ''), '/');
        $identifier = (string) $this->config->get('WHMCS_API_IDENTIFIER', '');
        $secret = (string) $this->config->get('WHMCS_API_SECRET', '');

        if ($baseUrl === '' || $identifier === '' || $secret === '') {
            throw new ApiException('whmcs_config_missing', 500);
        }

        $payload = array_merge($params, [
            'action' => $action,
            'identifier' => $identifier,
            'secret' => $secret,
            'responsetype' => 'json',
        ]);

        if ($this->requestHandler !== null) {
            $decoded = ($this->requestHandler)($action, $params);
            if (!is_array($decoded)) {
                throw new ApiException('whmcs_response_invalid', 502);
            }
            if (($decoded['result'] ?? '') !== 'success') {
                throw new ApiException('whmcs_response_error', 502, $decoded);
            }

            return $decoded;
        }

        $ch = curl_init($baseUrl . '/includes/api.php');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $error !== '') {
            throw new ApiException('whmcs_request_failed', 502, ['curl_error' => $error]);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new ApiException('whmcs_response_invalid', 502, ['status' => $status, 'body' => $response]);
        }

        if (($decoded['result'] ?? '') !== 'success') {
            // WHMCS explains the refusal here (for example "Invalid IP" when the panel is missing from the API allowlist).
            error_log('WHMCS API ' . $action . ' refused: ' . substr((string) ($decoded['message'] ?? ''), 0, 200));
            throw new ApiException('whmcs_response_error', 502, $decoded);
        }

        return $decoded;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchClientProducts(int $limit = 100, int $offset = 0, ?int $clientId = null): array
    {
        $params = [
            'limitnum' => $limit,
            'limitstart' => $offset,
        ];
        if ($clientId !== null && $clientId > 0) {
            $params['clientid'] = $clientId;
        }

        $response = $this->request('GetClientsProducts', $params);
        return $this->nestedList($response, 'products', 'product');
    }

    /**
     * @param array<string, mixed> $product
     * @return array{service_id:int, hostname:string, server_ip:string}
     */
    public function normalizePurchasedServerIdentity(array $product): array
    {
        $serviceId = (int) ($product['id'] ?? $product['serviceid'] ?? 0);
        $hostname = trim((string) ($product['domain'] ?? $product['hostname'] ?? ''));
        $serverIp = trim((string) ($product['dedicatedip'] ?? ''));
        if ($serverIp === '') {
            $assigned = trim((string) ($product['assignedips'] ?? ''));
            if ($assigned !== '') {
                $serverIp = trim((string) strtok($assigned, ','));
            }
        }

        return [
            'service_id' => $serviceId,
            'hostname' => $hostname,
            'server_ip' => $serverIp,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validateLogin(string $email, string $password): array
    {
        return $this->request('ValidateLogin', [
            'email' => $email,
            'password2' => $password,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getClientDetails(int $clientId, bool $stats = true): array
    {
        $response = $this->request('GetClientsDetails', [
            'clientid' => $clientId,
            'stats' => $stats,
        ]);

        $client = $response['client'] ?? null;
        if (!is_array($client)) {
            throw new ApiException('whmcs_client_not_found', 404, ['clientid' => $clientId]);
        }

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    public function getClientDetailsByEmail(string $email, bool $stats = true): array
    {
        $response = $this->request('GetClientsDetails', [
            'email' => $email,
            'stats' => $stats,
        ]);

        $client = $response['client'] ?? null;
        if (!is_array($client)) {
            throw new ApiException('whmcs_client_not_found', 404, ['email' => $email]);
        }

        return $client;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUsers(string $search): array
    {
        $response = $this->request('GetUsers', [
            'search' => $search,
        ]);

        return $this->listOfArrays($response['users'] ?? []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSupportDepartments(): array
    {
        $response = $this->request('GetSupportDepartments', []);
        return $this->nestedList($response, 'departments', 'department');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSupportStatuses(): array
    {
        $response = $this->request('GetSupportStatuses', []);
        return $this->nestedList($response, 'statuses', 'status');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function openTicket(array $params): array
    {
        return $this->request('OpenTicket', $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function updateTicket(int $ticketId, array $params = []): array
    {
        return $this->request('UpdateTicket', array_merge($params, [
            'ticketid' => $ticketId,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function addCredit(int $clientId, string $description, float $amount, string $type = 'add'): array
    {
        return $this->request('AddCredit', [
            'clientid' => $clientId,
            'description' => $description,
            'amount' => number_format($amount, 2, '.', ''),
            'type' => $type,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function nestedList(array $response, string ...$keys): array
    {
        $value = $response;
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? []) : [];
        }

        return $this->listOfArrays($value);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listOfArrays(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [];
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            return array_values(array_filter($value, static fn(mixed $item): bool => is_array($item)));
        }

        return [$value];
    }
}
