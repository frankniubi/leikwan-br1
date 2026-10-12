<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\AccessPolicyService;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\ApiRateLimiter;
use Leikwan\Master\Support\Auth;
use Leikwan\Master\Support\ClientOnlineSessionService;
use Leikwan\Master\Support\DeviceControlService;
use Leikwan\Master\Support\HostedNodeService;
use Leikwan\Master\Support\HostedNodeSpec;
use Leikwan\Master\Support\ProvisioningService;
use Leikwan\Master\Support\SessionAuth;
use Leikwan\Master\Support\TrustedClientIpResolver;

/**
 * Hosted user nodes ("上云"): the user's own Shadowsocks / SOCKS5 / VLESS
 * nodes stored by the panel per client, plus their forward pairing.
 *
 * Client API (/api/client/hosted-nodes*): only an official client of the
 * owning client may read or change them: a trusted, attested Android device,
 * or a Windows / macOS / Linux desktop presenting its per-install device id.
 * Every call goes through the same gates as that platform's config pull
 * (client token, access policy, device trust, online-device limit). Responses carry credentials, so they
 * are never cached and errors never echo input.
 *
 * Tenant console (/tenant/hosted-nodes): the signed-in tenant manages the
 * nodes of a client they own. Credentials are write-only there.
 */
final class HostedNodeController extends BaseController
{
    private const CLIENT_WRITE_LIMIT_PER_MINUTE = 30;

    private const PUBLIC_ERRORS = [
        'hosted_node_invalid' => 422,
        'hosted_node_not_found' => 404,
        'hosted_node_forward_not_found' => 404,
        'hosted_node_limit_reached' => 409,
        'hosted_node_duplicate' => 409,
        'hosted_nodes_unavailable' => 503,
        'hosted_node_client_invalid' => 422,
        'rate_limited' => 429,
    ];

    private HostedNodeService $nodes;
    private AccessPolicyService $accessPolicy;
    private DeviceControlService $deviceControls;

    public function __construct(
        \Leikwan\Master\Config $config,
        \Leikwan\Master\Database\Database $db,
        private ProvisioningService $provisioning,
        ?HostedNodeService $nodes = null,
        ?AccessPolicyService $accessPolicy = null,
        ?DeviceControlService $deviceControls = null,
    ) {
        parent::__construct($config, $db);
        $this->nodes = $nodes ?? HostedNodeService::fromConfig($config, $db);
        $this->accessPolicy = $accessPolicy ?? new AccessPolicyService(
            $db,
            clientIpResolver: new TrustedClientIpResolver((string) ($config->get('TRUSTED_PROXY_CIDRS', '') ?? '')),
        );
        $this->deviceControls = $deviceControls ?? DeviceControlService::fromConfig($config, $db);
    }

    // ---------------------------------------------------------------- client API

    public function clientList(Request $request, array $params): Response
    {
        $client = $this->trustedClient($request);
        return $this->clientResponse(fn(): array => $this->listingPayload($client));
    }

    public function clientCreate(Request $request, array $params): Response
    {
        $client = $this->trustedClient($request);
        return $this->clientResponse(function () use ($client, $request): array {
            $this->limitWrites($client);
            $result = $this->nodes->create((int) $client['id'], $this->nodeInput($request), HostedNodeService::SOURCE_DEVICE);
            $pairForwardId = (int) $request->input('pair_forward_id', 0);
            if ($pairForwardId > 0) {
                $this->nodes->setPairing($client, $pairForwardId, (int) $result['node']['id'], HostedNodeService::SOURCE_DEVICE);
            }
            return [
                'node_id' => (int) $result['node']['id'],
                'created' => $result['created'],
            ] + $this->listingPayload($client);
        });
    }

    /**
     * "Sync all local nodes to cloud": up to MAX_NODES_PER_CLIENT share links
     * in one write. Each entry succeeds or fails on its own; uploads are
     * idempotent, so a device can resend the whole batch after a failure.
     */
    public function clientBatchCreate(Request $request, array $params): Response
    {
        $client = $this->trustedClient($request);
        return $this->clientResponse(function () use ($client, $request): array {
            $this->limitWrites($client);
            $entries = $request->input('nodes');
            if (!is_array($entries) || !array_is_list($entries) || $entries === []
                || count($entries) > HostedNodeService::MAX_NODES_PER_CLIENT) {
                throw new ApiException('hosted_node_invalid', 422, ['field' => 'nodes']);
            }
            $results = [];
            foreach ($entries as $entry) {
                $entry = is_array($entry) ? $entry : [];
                $ref = substr(trim((string) ($entry['ref'] ?? '')), 0, 64);
                $input = [];
                foreach (['uri', 'name'] as $field) {
                    if (isset($entry[$field]) && is_scalar($entry[$field])) {
                        $input[$field] = (string) $entry[$field];
                    }
                }
                try {
                    if (trim((string) ($input['uri'] ?? '')) === '') {
                        throw new ApiException('hosted_node_invalid', 422, ['field' => 'uri']);
                    }
                    $created = $this->nodes->create((int) $client['id'], $input, HostedNodeService::SOURCE_DEVICE);
                    $results[] = ['ref' => $ref, 'ok' => true, 'node_id' => (int) $created['node']['id'], 'created' => $created['created']];
                } catch (ApiException $exception) {
                    $code = $exception->getMessage();
                    if (!array_key_exists($code, self::PUBLIC_ERRORS)) {
                        throw $exception;
                    }
                    $result = ['ref' => $ref, 'ok' => false, 'error' => $code];
                    $field = $exception->details()['field'] ?? null;
                    if (is_string($field)) {
                        $result['field'] = $field;
                    }
                    $results[] = $result;
                }
            }
            return ['results' => $results] + $this->listingPayload($client);
        });
    }

    public function clientUpdate(Request $request, array $params): Response
    {
        $client = $this->trustedClient($request);
        return $this->clientResponse(function () use ($client, $request, $params): array {
            $this->limitWrites($client);
            $node = $this->nodes->update((int) $client['id'], $this->idParam($params), $this->nodeInput($request));
            return ['node_id' => (int) $node['id']] + $this->listingPayload($client);
        });
    }

    public function clientDelete(Request $request, array $params): Response
    {
        $client = $this->trustedClient($request);
        return $this->clientResponse(function () use ($client, $params): array {
            $this->limitWrites($client);
            if (!$this->nodes->delete((int) $client['id'], $this->idParam($params))) {
                throw new ApiException('hosted_node_not_found', 404);
            }
            return $this->listingPayload($client);
        });
    }

    public function clientPair(Request $request, array $params): Response
    {
        $client = $this->trustedClient($request);
        return $this->clientResponse(function () use ($client, $request, $params): array {
            $this->limitWrites($client);
            $raw = $request->input('node_id');
            $nodeId = $raw === null || $raw === '' ? null : (int) $raw;
            if ($nodeId !== null && $nodeId <= 0) {
                throw new ApiException('hosted_node_not_found', 404);
            }
            $this->nodes->setPairing($client, $this->idParam($params, 'forwardId'), $nodeId, HostedNodeService::SOURCE_DEVICE);
            return $this->listingPayload($client);
        });
    }

    /**
     * Same gates as /api/client/configs/pull for the official clients:
     *
     * - Android: only an enrolled, attested device with a live device session
     *   passes DeviceControlService::requireActive.
     * - Desktop (Windows / macOS / Linux headless): the identity the desktop
     *   pull itself relies on, i.e. the client agent token, the access policy
     *   (geo lock, blacklist), an explicit per-install device id of that
     *   platform's own format (never the server-derived legacy id, so the call
     *   is a visible, kickable device session) and the online-device limit.
     *   A request that presents an attested Android device id is always held
     *   to the Android gate, whatever platform it claims, so a trusted phone's
     *   id cannot be laundered through the desktop path.
     *
     * Hosted nodes never give the forward core a new capability (the panel
     * only stores the user's own nodes), so the desktop gate matches the
     * desktop config pull rather than inventing a stronger one it cannot meet.
     *
     * @return array<string, mixed>
     */
    private function trustedClient(Request $request): array
    {
        $client = Auth::requireClientAgent($request, $this->db);
        $platform = strtolower(trim((string) ($request->header('x-device-platform') ?? '')));
        $headerDeviceId = trim((string) ($request->header('x-device-id') ?? ''));
        $android = str_starts_with($platform, 'android') || $this->deviceControls->isManagedDeviceId($headerDeviceId);
        $desktopFamily = $android ? '' : self::desktopFamily($platform);
        if (!$android && $desktopFamily === '') {
            throw new ApiException('device_untrusted', 403);
        }
        $sessions = new ClientOnlineSessionService(
            $this->db,
            new TrustedClientIpResolver((string) ($this->config->get('TRUSTED_PROXY_CIDRS', '') ?? '')),
        );
        $deviceId = $sessions->deviceIdFromRequest($request);
        if (!$android && !self::isDesktopDeviceId($desktopFamily, $deviceId, (int) ($client['id'] ?? 0))) {
            throw new ApiException('device_untrusted', 403);
        }
        $this->accessPolicy->authorizeClientRequest($client, $request, 'request', $deviceId);
        if ($android) {
            $this->deviceControls->requireActive($client, $request);
        }
        $sessions->enforceFromRequest($client, $request, 'request');
        return $client;
    }

    /** Desktop platform family from X-Device-Platform ('windows-desktop', 'macos-desktop', 'linux'), or ''. */
    private static function desktopFamily(string $platform): string
    {
        foreach (['windows', 'macos', 'linux'] as $family) {
            if ($platform === $family || str_starts_with($platform, $family . '-')) {
                return $family;
            }
        }
        return '';
    }

    /**
     * The device id each official desktop build persists per install:
     * Windows windows-client-<client id>-<16 hex> (bound to this client),
     * macOS macos-client-<32 hex>, Linux headless linux-<32 hex>.
     */
    private static function isDesktopDeviceId(string $family, string $deviceId, int $clientId): bool
    {
        return match ($family) {
            'windows' => preg_match('/^windows-client-([1-9][0-9]*)-[a-f0-9]{16}$/', $deviceId, $match) === 1
                && (int) $match[1] === $clientId,
            'macos' => preg_match('/^macos-client-[a-f0-9]{32}$/', $deviceId) === 1,
            'linux' => preg_match('/^linux-[a-f0-9]{32}$/', $deviceId) === 1,
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function listingPayload(array $client): array
    {
        return $this->nodes->clientListing($client);
    }

    /** @param array<string, mixed> $client */
    private function limitWrites(array $client): void
    {
        ApiRateLimiter::fromConfig($this->config)->hit(
            'hosted-nodes:client:' . (int) $client['id'],
            self::CLIENT_WRITE_LIMIT_PER_MINUTE
        );
    }

    /** @return array<string, mixed> */
    private function nodeInput(Request $request): array
    {
        $input = [];
        foreach ([
            'uri', 'name', 'protocol', 'server', 'port',
            'method', 'password', 'plugin', 'plugin_opts', 'udp_over_tcp',
            'username',
            'uuid', 'flow', 'security', 'sni', 'alpn', 'fingerprint', 'insecure',
            'public_key', 'short_id', 'transport', 'path', 'host', 'service_name',
        ] as $field) {
            $value = $request->input($field);
            if ($value !== null && (is_scalar($value) || ($field === 'alpn' && is_array($value)))) {
                $input[$field] = $value;
            }
        }
        return $input;
    }

    /** @param callable(): array<string, mixed> $action */
    private function clientResponse(callable $action): Response
    {
        try {
            $payload = ['ok' => true] + $action();
            $status = 200;
        } catch (ApiException $exception) {
            $code = $exception->getMessage();
            if (!array_key_exists($code, self::PUBLIC_ERRORS)) {
                throw $exception;
            }
            $payload = ['ok' => false, 'error' => $code];
            $details = $exception->details();
            if (isset($details['field']) && is_string($details['field'])) {
                $payload['field'] = $details['field'];
            }
            if (isset($details['limit'])) {
                $payload['limit'] = (int) $details['limit'];
            }
            if (isset($details['retry_after'])) {
                $payload['retry_after'] = (int) $details['retry_after'];
            }
            $status = self::PUBLIC_ERRORS[$code];
        }
        return $this->json($payload, $status)->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ---------------------------------------------------------- tenant console

    public function tenantPage(Request $request, array $params): Response
    {
        $user = $this->tenantUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $client = $this->tenantClient($user, (int) $request->input('client_id', 0));
        if ($client === null) {
            SessionAuth::flash('error', 'Client is not available.');
            return Response::redirect('/tenant/clients');
        }
        $clientId = (int) $client['id'];
        $csrf = $this->escape(SessionAuth::csrfToken());
        $available = $this->nodes->available();

        $nodes = $available ? $this->nodes->consoleNodes($clientId) : [];
        $forwards = $this->nodes->forwardsForClient($client);
        $pairings = [];
        if ($nodes !== []) {
            $rawNodes = $this->nodes->nodesForClient($clientId);
            foreach ($this->nodes->effectivePairings($client, $rawNodes, $forwards) as $pairing) {
                $pairings[(int) $pairing['forward_id']] = $pairing;
            }
        }
        $nodesById = [];
        foreach ($nodes as $node) {
            $nodesById[(int) $node['id']] = $node;
        }

        $clientPicker = '';
        $owned = $this->ownedClients((int) $user['id']);
        if (count($owned) > 1) {
            $options = '';
            foreach ($owned as $row) {
                $options .= '<option value="' . (int) $row['id'] . '"' . ((int) $row['id'] === $clientId ? ' selected' : '') . '>'
                    . $this->escape((string) $row['name']) . ' (#' . (int) $row['id'] . ')</option>';
            }
            $clientPicker = '<form method="get" action="/tenant/hosted-nodes" class="actions">'
                . '<select name="client_id">' . $options . '</select>'
                . '<button class="button ghost" type="submit">Switch client</button></form>';
        }

        $intro = '<div class="hero"><div>'
            . '<p class="muted">Your own nodes, stored by the panel for this client. Every signed-in device of the client receives them, together with which service forward each node rides on. The panel only stores and syncs them; traffic still goes from your device through the forward to your node.</p>'
            . '<p class="muted">Client #' . $clientId . ' · ' . $this->escape((string) ($client['name'] ?? '')) . '</p>'
            . $clientPicker . '</div></div>';

        if (!$available) {
            $body = $intro . '<section class="card"><p class="muted">Hosted nodes are not available on this panel yet.</p></section>';
            return $this->page('My Nodes', $body, $user, $this->tenantNavigationItems('hosted-nodes'));
        }

        $rows = '';
        foreach ($nodes as $node) {
            $detail = match ((string) $node['protocol']) {
                HostedNodeSpec::PROTOCOL_SHADOWSOCKS => (string) $node['method'] . ((string) $node['plugin'] !== '' ? ' + ' . (string) $node['plugin'] : ''),
                HostedNodeSpec::PROTOCOL_SOCKS5 => !empty($node['auth']) ? 'Password' : 'No auth',
                default => (string) $node['security'] . ' / ' . (string) $node['transport'],
            };
            $server = (string) $node['server'];
            $endpoint = (str_contains($server, ':') ? '[' . $server . ']' : $server) . ':' . (int) $node['port'];
            $rows .= '<tr>'
                . '<td>' . $this->escape((string) $node['name']) . '</td>'
                . '<td>' . $this->escape($this->protocolLabel((string) $node['protocol'])) . '</td>'
                . '<td><code>' . $this->escape($endpoint) . '</code></td>'
                . '<td>' . $this->escape($detail) . '</td>'
                . '<td>' . $this->escape((string) $node['source'] === HostedNodeService::SOURCE_DEVICE ? 'Device' : 'Console') . '</td>'
                . '<td class="actions"><form class="inline" method="post" action="/tenant/hosted-nodes/' . (int) $node['id'] . '/delete" data-confirm="Delete this node from every device of the client?" onsubmit="return confirm(this.dataset.confirm);">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<input type="hidden" name="client_id" value="' . $clientId . '">'
                . '<button class="button ghost" type="submit">Delete</button></form></td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="muted">No hosted nodes yet.</td></tr>';
        }
        $nodesCard = '<section class="card"><h3>Nodes</h3>'
            . '<div class="table-wrap"><table><thead><tr><th>Name</th><th>Protocol</th><th>Server</th><th>Settings</th><th>Added from</th><th></th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>'
            . '<p class="muted">Passwords, UUIDs and keys are never shown again after saving. To change one, delete the node and add it again.</p></section>';

        $pairRows = '';
        $serviceNames = $this->serviceNames($forwards);
        foreach ($forwards as $forward) {
            $forwardId = (int) $forward['id'];
            $current = $pairings[$forwardId] ?? null;
            $options = '<option value="">' . $this->escape($current !== null && $current['source'] === 'target_match' ? 'Automatic (matches target)' : 'Not paired') . '</option>';
            foreach ($nodes as $node) {
                $selected = $current !== null && $current['source'] === 'explicit' && (int) $current['node_id'] === (int) $node['id'];
                $options .= '<option value="' . (int) $node['id'] . '"' . ($selected ? ' selected' : '') . '>' . $this->escape((string) $node['name']) . '</option>';
            }
            $state = 'Not paired';
            $warning = '';
            if ($current !== null) {
                $paired = $nodesById[(int) $current['node_id']] ?? null;
                $state = ($current['source'] === 'target_match' ? 'Automatic: ' : 'Paired: ') . (string) ($paired['name'] ?? '');
                if ($paired !== null && !$this->targetMatches($forward, $paired)) {
                    $warning = '<div class="muted">The forward target is not this node\'s address. Traffic only reaches the node when the forward targets its server and port.</div>';
                }
            }
            $target = (string) $forward['target_host'] . ':' . (int) $forward['target_port'];
            $pairRows .= '<tr>'
                . '<td>' . $this->escape((string) ($serviceNames[(int) $forward['service_id']] ?? ('Service #' . (int) $forward['service_id']))) . ' · #' . $forwardId . '</td>'
                . '<td><code>' . $this->escape($target) . '</code></td>'
                . '<td>' . $this->escape($state) . $warning . '</td>'
                . '<td><form class="inline" method="post" action="/tenant/hosted-nodes/pairings">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<input type="hidden" name="client_id" value="' . $clientId . '">'
                . '<input type="hidden" name="forward_id" value="' . $forwardId . '">'
                . '<select name="node_id">' . $options . '</select>'
                . '<button class="button ghost" type="submit">Save</button></form></td>'
                . '</tr>';
        }
        if ($pairRows === '') {
            $pairRows = '<tr><td colspan="4" class="muted">This client has no forwards yet.</td></tr>';
        }
        $pairCard = '<section class="card"><h3>Pairing</h3>'
            . '<p class="muted">Choose which of your nodes each forward carries. Without a choice, a forward pairs automatically with the one node whose server and port equal its target.</p>'
            . '<div class="table-wrap"><table><thead><tr><th>Forward</th><th>Target</th><th>Node</th><th></th></tr></thead><tbody>'
            . $pairRows . '</tbody></table></div></section>';

        $addCard = '<section class="card"><h3>Add a node</h3>'
            . '<form method="post" action="/tenant/hosted-nodes">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="client_id" value="' . $clientId . '">'
            . '<div class="form-grid">'
            . '<div class="field"><label for="hn-uri">Share link</label><input id="hn-uri" type="text" name="uri" autocomplete="off" placeholder="ss://… socks5://… vless://…"><p class="muted">Paste a share link, or leave it empty and fill in the fields below.</p></div>'
            . '<div class="field"><label for="hn-name">Name</label><input id="hn-name" type="text" name="name" maxlength="64" placeholder="Optional"></div>'
            . '<div class="field"><label for="hn-protocol">Protocol</label><select id="hn-protocol" name="protocol"><option value="">From share link</option><option value="shadowsocks">Shadowsocks</option><option value="socks5">SOCKS5</option><option value="vless">VLESS</option></select></div>'
            . '<div class="field"><label for="hn-server">Server</label><input id="hn-server" type="text" name="server" autocomplete="off" placeholder="node.example.com"></div>'
            . '<div class="field"><label for="hn-port">Port</label><input id="hn-port" type="number" min="1" max="65535" name="port"></div>'
            . '</div>'
            . '<details><summary>Shadowsocks</summary><div class="form-grid">'
            . '<div class="field"><label for="hn-method">Method</label><select id="hn-method" name="method">' . $this->optionList(HostedNodeSpec::SS_METHODS, '2022-blake3-aes-128-gcm') . '</select></div>'
            . '<div class="field"><label for="hn-ss-password">Password</label><input id="hn-ss-password" type="password" name="ss_password" autocomplete="new-password"></div>'
            . '<div class="field"><label for="hn-plugin">Plugin</label><select id="hn-plugin" name="plugin"><option value="">None</option><option value="obfs-local">obfs-local</option><option value="v2ray-plugin">v2ray-plugin</option></select></div>'
            . '<div class="field"><label for="hn-plugin-opts">Plugin options</label><input id="hn-plugin-opts" type="text" name="plugin_opts" placeholder="obfs=http;obfs-host=example.com"></div>'
            . '</div></details>'
            . '<details><summary>SOCKS5</summary><div class="form-grid">'
            . '<div class="field"><label for="hn-username">Username</label><input id="hn-username" type="text" name="username" autocomplete="off" placeholder="Optional"></div>'
            . '<div class="field"><label for="hn-socks-password">Password</label><input id="hn-socks-password" type="password" name="socks_password" autocomplete="new-password" placeholder="Optional"></div>'
            . '</div></details>'
            . '<details><summary>VLESS</summary><div class="form-grid">'
            . '<div class="field"><label for="hn-uuid">UUID</label><input id="hn-uuid" type="text" name="uuid" autocomplete="off"></div>'
            . '<div class="field"><label for="hn-security">Security</label><select id="hn-security" name="security"><option value="none">None</option><option value="tls">TLS</option><option value="reality">REALITY</option></select></div>'
            . '<div class="field"><label for="hn-sni">Server name (SNI)</label><input id="hn-sni" type="text" name="sni"></div>'
            . '<div class="field"><label for="hn-fp">uTLS fingerprint</label><select id="hn-fp" name="fingerprint"><option value="">Default</option>' . $this->optionList(HostedNodeSpec::UTLS_FINGERPRINTS, '') . '</select></div>'
            . '<div class="field"><label for="hn-pbk">REALITY public key</label><input id="hn-pbk" type="text" name="public_key" autocomplete="off"></div>'
            . '<div class="field"><label for="hn-sid">REALITY short ID</label><input id="hn-sid" type="text" name="short_id" autocomplete="off"></div>'
            . '<div class="field"><label for="hn-flow">Flow</label><select id="hn-flow" name="flow"><option value="">None</option><option value="xtls-rprx-vision">xtls-rprx-vision</option></select></div>'
            . '<div class="field"><label for="hn-transport">Transport</label><select id="hn-transport" name="transport">' . $this->optionList(HostedNodeSpec::VLESS_TRANSPORTS, 'tcp') . '</select></div>'
            . '<div class="field"><label for="hn-path">Path</label><input id="hn-path" type="text" name="path" placeholder="/"></div>'
            . '<div class="field"><label for="hn-host">Host header</label><input id="hn-host" type="text" name="host"></div>'
            . '<div class="field"><label for="hn-service-name">gRPC service name</label><input id="hn-service-name" type="text" name="service_name"></div>'
            . '</div></details>'
            . '<div class="actions"><button class="button" type="submit">Save node</button></div>'
            . '</form></section>';

        $body = $intro . $nodesCard . $pairCard . $addCard;
        return $this->page('My Nodes', $body, $user, $this->tenantNavigationItems('hosted-nodes'));
    }

    public function tenantCreate(Request $request, array $params): Response
    {
        $user = $this->tenantUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $clientId = (int) $request->input('client_id', 0);
        try {
            SessionAuth::validateCsrf($request);
            $client = $this->tenantClient($user, $clientId);
            if ($client === null) {
                throw new ApiException('hosted_node_client_invalid', 422);
            }
            $clientId = (int) $client['id'];
            $result = $this->nodes->create($clientId, $this->tenantNodeInput($request), HostedNodeService::SOURCE_CONSOLE, (int) $user['id']);
            SessionAuth::flash('success', $result['created'] ? 'Node saved.' : 'This node is already saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', $this->tenantErrorMessage($throwable));
        }
        return Response::redirect($this->tenantPageUrl($clientId));
    }

    public function tenantDelete(Request $request, array $params): Response
    {
        $user = $this->tenantUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $clientId = (int) $request->input('client_id', 0);
        try {
            SessionAuth::validateCsrf($request);
            $client = $this->tenantClient($user, $clientId);
            if ($client === null) {
                throw new ApiException('hosted_node_client_invalid', 422);
            }
            $clientId = (int) $client['id'];
            if (!$this->nodes->delete($clientId, $this->idParam($params))) {
                throw new ApiException('hosted_node_not_found', 404);
            }
            SessionAuth::flash('success', 'Node deleted.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', $this->tenantErrorMessage($throwable));
        }
        return Response::redirect($this->tenantPageUrl($clientId));
    }

    public function tenantPair(Request $request, array $params): Response
    {
        $user = $this->tenantUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $clientId = (int) $request->input('client_id', 0);
        try {
            SessionAuth::validateCsrf($request);
            $client = $this->tenantClient($user, $clientId);
            if ($client === null) {
                throw new ApiException('hosted_node_client_invalid', 422);
            }
            $clientId = (int) $client['id'];
            $rawNode = trim((string) $request->input('node_id', ''));
            $this->nodes->setPairing(
                $client,
                (int) $request->input('forward_id', 0),
                $rawNode === '' ? null : (int) $rawNode,
                HostedNodeService::SOURCE_CONSOLE
            );
            SessionAuth::flash('success', 'Pairing saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', $this->tenantErrorMessage($throwable));
        }
        return Response::redirect($this->tenantPageUrl($clientId));
    }

    /** @return array<string, mixed>|null */
    private function tenantUser(): ?array
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) ($user['role'] ?? '') !== 'tenant') {
            return null;
        }
        return $user;
    }

    /**
     * The requested client when the tenant owns it, else the tenant's default
     * client. A client id the tenant does not own never resolves.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    private function tenantClient(array $user, int $requestedClientId): ?array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }
        if ($requestedClientId > 0) {
            $row = $this->db->fetchOne(
                'SELECT * FROM clients WHERE id = :id AND owner_user_id = :owner_user_id AND status = :status LIMIT 1',
                ['id' => $requestedClientId, 'owner_user_id' => $userId, 'status' => 'active']
            );
            return is_array($row) ? $row : null;
        }
        $client = $this->provisioning->defaultClientForUser($userId);
        if ($client === null) {
            try {
                $client = $this->provisioning->ensureDefaultClientForUser($userId);
            } catch (\Throwable) {
                return null;
            }
        }
        return (int) ($client['id'] ?? 0) > 0 ? $client : null;
    }

    /** @return list<array<string, mixed>> */
    private function ownedClients(int $userId): array
    {
        return array_values($this->db->fetchAll(
            'SELECT id, name FROM clients WHERE owner_user_id = :owner_user_id AND status = :status AND group_id IS NULL ORDER BY id ASC',
            ['owner_user_id' => $userId, 'status' => 'active']
        ));
    }

    /** @return array<string, mixed> */
    private function tenantNodeInput(Request $request): array
    {
        $input = [];
        foreach ([
            'uri', 'name', 'protocol', 'server', 'port', 'method', 'plugin', 'plugin_opts', 'username',
            'uuid', 'flow', 'security', 'sni', 'fingerprint', 'public_key', 'short_id', 'transport',
            'path', 'host', 'service_name',
        ] as $field) {
            $value = $request->input($field);
            if (is_scalar($value) && trim((string) $value) !== '') {
                $input[$field] = (string) $value;
            }
        }
        // One form holds both protocols' password fields.
        $protocol = strtolower((string) ($input['protocol'] ?? ''));
        $passwordField = $protocol === HostedNodeSpec::PROTOCOL_SOCKS5 ? 'socks_password' : 'ss_password';
        $password = $request->input($passwordField);
        if (is_scalar($password) && (string) $password !== '') {
            $input['password'] = (string) $password;
        }
        return $input;
    }

    private function tenantErrorMessage(\Throwable $throwable): string
    {
        if (!$throwable instanceof ApiException) {
            return 'The node could not be saved.';
        }
        $details = $throwable->details();
        return match ($throwable->getMessage()) {
            'hosted_node_invalid' => 'Check the node settings: ' . $this->fieldLabel((string) ($details['field'] ?? '')) . '.',
            'hosted_node_limit_reached' => 'This client already has the maximum number of nodes.',
            'hosted_node_duplicate' => 'Another node already uses these settings.',
            'hosted_node_not_found' => 'Node not found.',
            'hosted_node_forward_not_found' => 'Forward not found for this client.',
            'hosted_nodes_unavailable' => 'Hosted nodes are not available on this panel yet.',
            'hosted_node_client_invalid' => 'Client is not available.',
            default => 'The node could not be saved.',
        };
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'uri' => 'share link',
            'protocol' => 'protocol',
            'server' => 'server',
            'port' => 'port',
            'name' => 'name',
            'method' => 'method',
            'password' => 'password',
            'plugin', 'plugin_opts' => 'plugin',
            'username' => 'username',
            'uuid' => 'UUID',
            'flow' => 'flow',
            'security', 'encryption' => 'security',
            'sni' => 'server name',
            'fingerprint' => 'fingerprint',
            'alpn' => 'ALPN',
            'public_key' => 'REALITY public key',
            'short_id' => 'REALITY short ID',
            'transport' => 'transport',
            'path' => 'path',
            'host' => 'host header',
            'service_name' => 'gRPC service name',
            default => 'settings',
        };
    }

    private function tenantPageUrl(int $clientId): string
    {
        return '/tenant/hosted-nodes' . ($clientId > 0 ? '?client_id=' . $clientId : '');
    }

    /**
     * @param list<array<string, mixed>> $forwards
     * @return array<int, string>
     */
    private function serviceNames(array $forwards): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(array $forward): int => (int) $forward['service_id'],
            $forwards
        ))));
        if ($ids === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $placeholders[] = ':s' . $index;
            $params['s' . $index] = $id;
        }
        $names = [];
        foreach ($this->db->fetchAll('SELECT id, name FROM services WHERE id IN (' . implode(', ', $placeholders) . ')', $params) as $row) {
            $names[(int) $row['id']] = (string) ($row['name'] ?? '');
        }
        return $names;
    }

    /**
     * @param array<string, mixed> $forward
     * @param array<string, mixed> $node
     */
    private function targetMatches(array $forward, array $node): bool
    {
        $host = strtolower(trim((string) $forward['target_host'], " []"));
        return $host === strtolower((string) $node['server']) && (int) $forward['target_port'] === (int) $node['port'];
    }

    private function protocolLabel(string $protocol): string
    {
        return match ($protocol) {
            HostedNodeSpec::PROTOCOL_SHADOWSOCKS => 'Shadowsocks',
            HostedNodeSpec::PROTOCOL_SOCKS5 => 'SOCKS5',
            HostedNodeSpec::PROTOCOL_VLESS => 'VLESS',
            default => $protocol,
        };
    }

    /** @param list<string> $values */
    private function optionList(array $values, string $selected): string
    {
        $html = '';
        foreach ($values as $value) {
            $html .= '<option value="' . $this->escape($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . $this->escape($value) . '</option>';
        }
        return $html;
    }
}
