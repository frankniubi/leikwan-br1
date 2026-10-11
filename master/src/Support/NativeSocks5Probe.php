<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Throwable;

final class NativeSocks5ProbeResult
{
    public function __construct(
        public bool $succeeded,
        public bool $tcpSucceeded,
        public bool $udpSucceeded,
        public string $errorCode,
    ) {
    }

    /** @return array{succeeded:bool,tcpSucceeded:bool,udpSucceeded:bool,errorCode:string} */
    public function toArray(): array
    {
        return [
            'succeeded' => $this->succeeded,
            'tcpSucceeded' => $this->tcpSucceeded,
            'udpSucceeded' => $this->udpSucceeded,
            'errorCode' => $this->errorCode,
        ];
    }
}

final class NativeSocks5Probe
{
    private const VERSION = 5;
    private const CMD_CONNECT = 1;
    private const CMD_UDP_ASSOCIATE = 3;
    private const CURRENT_GREETING = [5, 5, 0, 1, 2, 0x80, 0x83, 1];
    private const LEGACY_GREETING = [5, 3, 2, 0x80, 0x83, 1];
    private const AUTH_USERNAME_PASSWORD = 2;
    private const DEFAULT_TIMEOUT_SECONDS = 8;
    private const UDP_KEEPALIVE = "\x00\x00\xa4\x64\x34\xc9\xb6\x2b\x82\xee\xd2\xa8\x7f\xaa\x6a\x5f\x45\xac";

    public static function verify(
        string $host,
        int $port,
        string $user,
        string $password,
        string $targetHost,
        int $targetPort,
    ): NativeSocks5ProbeResult {
        if (!self::validHost($host) || $port < 1 || $port > 65535
            || $user === '' || strlen($user) > 255
            || $password === '' || strlen($password) > 255
            || !self::validHost($targetHost) || $targetPort < 1 || $targetPort > 65535) {
            return new NativeSocks5ProbeResult(false, false, false, 'probe_input_invalid');
        }

        $tcpSucceeded = false;
        try {
            $tcpSucceeded = self::verifyTcp($host, $port, $user, $password, $targetHost, $targetPort);
        } catch (ProbeFailure $failure) {
            return new NativeSocks5ProbeResult(false, false, false, $failure->errorCode);
        } catch (Throwable) {
            return new NativeSocks5ProbeResult(false, false, false, 'tcp_probe_failed');
        }

        $udpSucceeded = false;
        try {
            $udpSucceeded = self::verifyUdp($host, $port, $user, $password);
        } catch (ProbeFailure $failure) {
            return new NativeSocks5ProbeResult(false, $tcpSucceeded, false, $failure->errorCode);
        } catch (Throwable) {
            return new NativeSocks5ProbeResult(false, $tcpSucceeded, false, 'udp_probe_failed');
        }

        return new NativeSocks5ProbeResult($tcpSucceeded && $udpSucceeded, $tcpSucceeded, $udpSucceeded, '');
    }

    private static function verifyTcp(
        string $host,
        int $port,
        string $user,
        string $password,
        string $targetHost,
        int $targetPort,
    ): bool {
        $lastError = 'tcp_probe_failed';
        foreach ([self::CURRENT_GREETING, self::LEGACY_GREETING] as $greeting) {
            $stream = null;
            try {
                $stream = self::openTcp($host, $port);
                self::writeAll($stream, self::ultraHandshake($user, $password, self::CMD_CONNECT, $targetHost, $targetPort, $greeting));
                self::readStreamAck($stream);
                fclose($stream);
                return true;
            } catch (ProbeFailure $failure) {
                $lastError = $failure->errorCode;
                if (is_resource($stream)) {
                    fclose($stream);
                }
            } catch (Throwable) {
                $lastError = 'tcp_probe_failed';
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
        throw new ProbeFailure($lastError);
    }

    private static function verifyUdp(string $host, int $port, string $user, string $password): bool
    {
        $lastError = 'udp_probe_failed';
        foreach ([self::CURRENT_GREETING, self::LEGACY_GREETING] as $greeting) {
            $control = null;
            $udp = null;
            try {
                $control = self::openTcp($host, $port);
                self::writeAll($control, self::ultraHandshake($user, $password, self::CMD_UDP_ASSOCIATE, $host, $port, $greeting));
                $reply = self::readControlReply($control);
                $marker = strrpos($reply, "\x04\x01\x01\x00\x01");
                if ($marker === false || strlen($reply) < $marker + 10) {
                    throw new ProbeFailure('udp_control_reply_invalid');
                }
                $mode = ord($reply[$marker + 5]) ^ 1;
                $sidPrefix = [ord($reply[$marker + 6]) ^ 1, ord($reply[$marker + 7]) ^ 1];
                $encodedPort = unpack('n', substr($reply, $marker + 8, 2))[1];
                $remotePort = $encodedPort ^ 0x0101;
                if ($remotePort < 1 || $remotePort > 65535) {
                    throw new ProbeFailure('udp_port_invalid');
                }
                $udp = self::openUdp($host, $remotePort);
                $armed = false;
                // SID negotiation is intentionally bounded per candidate. A
                // dead candidate must not consume the full probe timeout and
                // prevent the remaining relay slots from being checked.
                stream_set_timeout($udp, 0, 250000);
                for ($candidate = 0x20; $candidate < 0x40; $candidate++) {
                    $sid = chr($sidPrefix[0]) . chr($sidPrefix[1]) . chr($candidate);
                    $packet = "\x01\x00" . chr($mode) . $sid . "\x00\x00\x00\x01" . inet_pton('2.0.0.0') . pack('n', 0) . self::UDP_KEEPALIVE;
                    self::writeAll($udp, $packet);
                    try {
                        $response = self::readDatagram($udp);
                    } catch (ProbeFailure $failure) {
                        if ($failure->errorCode === 'probe_timeout') {
                            continue;
                        }
                        throw $failure;
                    }
                    if (self::isReservedResponse($response)) {
                        $armed = true;
                        break;
                    }
                }
                if (!$armed) {
                    throw new ProbeFailure('udp_association_rejected');
                }
                fclose($udp);
                fclose($control);
                return true;
            } catch (ProbeFailure $failure) {
                $lastError = $failure->errorCode;
                if (is_resource($udp)) {
                    fclose($udp);
                }
                if (is_resource($control)) {
                    fclose($control);
                }
            } catch (Throwable) {
                $lastError = 'udp_probe_failed';
                if (is_resource($udp)) {
                    fclose($udp);
                }
                if (is_resource($control)) {
                    fclose($control);
                }
            }
        }
        throw new ProbeFailure($lastError);
    }

    /** @return resource */
    private static function openTcp(string $host, int $port)
    {
        $address = self::formatAddress($host);
        $errno = 0;
        $error = '';
        $stream = @stream_socket_client(
            'tcp://' . $address . ':' . $port,
            $errno,
            $error,
            self::DEFAULT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($stream)) {
            throw new ProbeFailure('tcp_connect_failed');
        }
        stream_set_timeout($stream, self::DEFAULT_TIMEOUT_SECONDS);
        return $stream;
    }

    /** @return resource */
    private static function openUdp(string $host, int $port)
    {
        $errno = 0;
        $error = '';
        $stream = @stream_socket_client(
            'udp://' . self::formatAddress($host) . ':' . $port,
            $errno,
            $error,
            self::DEFAULT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($stream)) {
            throw new ProbeFailure('udp_socket_failed');
        }
        stream_set_timeout($stream, self::DEFAULT_TIMEOUT_SECONDS);
        return $stream;
    }

    private static function ultraHandshake(
        string $user,
        string $credential,
        int $command,
        string $host,
        int $port,
        array $greeting,
    ): string {
        if ($user === '' || strlen($user) > 255 || $credential === '' || strlen($credential) > 255) {
            throw new ProbeFailure('probe_credential_invalid');
        }
        if (!in_array($command, [self::CMD_CONNECT, self::CMD_UDP_ASSOCIATE], true)) {
            throw new ProbeFailure('probe_command_invalid');
        }
        $prefix = '';
        foreach ($greeting as $byte) {
            $prefix .= chr($byte);
        }
        return $prefix
            . chr(strlen($user)) . $user
            . chr(strlen($credential)) . $credential
            . chr(self::VERSION) . chr($command) . chr(self::credentialChecksum($credential))
            . self::encodeAddress($host) . pack('n', $port);
    }

    private static function credentialChecksum(string $credential): int
    {
        if (strlen($credential) !== 16) {
            return 0;
        }
        return (0x74 - (ord($credential[6]) - 48) - (ord($credential[10]) - 48)) & 0xff;
    }

    /** @param resource $stream */
    private static function readStreamAck($stream): void
    {
        $first = self::readExact($stream, 2);
        if ($first === "\x05\x02") {
            $tail = self::readExact($stream, 2);
            if ($tail !== "\x01\x00") {
                throw new ProbeFailure('tcp_ack_invalid');
            }
            return;
        }
        $prelude = $first . self::readExact($stream, 12);
        if (substr($prelude, 0, 8) !== str_repeat("\x00", 8) || ord($prelude[8]) === 0) {
            throw new ProbeFailure('tcp_ack_invalid');
        }
    }

    /** @param resource $stream */
    private static function readControlReply($stream): string
    {
        $buffer = '';
        while (strlen($buffer) < 512) {
            $chunk = @fread($stream, 128);
            if (!is_string($chunk) || $chunk === '') {
                $meta = stream_get_meta_data($stream);
                throw new ProbeFailure(!empty($meta['timed_out']) ? 'probe_timeout' : 'probe_read_failed');
            }
            $buffer .= $chunk;
            $marker = strrpos($buffer, "\x04\x01\x01\x00\x01");
            if ($marker !== false && strlen($buffer) >= $marker + 10) {
                return $buffer;
            }
        }
        throw new ProbeFailure('udp_control_reply_invalid');
    }

    private static function encodeAddress(string $host): string
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip !== false) {
            $packed = inet_pton($ip);
            if ($packed === false) {
                throw new ProbeFailure('probe_target_invalid');
            }
            return chr(str_contains($ip, ':') ? 4 : 1) . $packed;
        }
        $bytes = $host;
        if ($bytes === '' || strlen($bytes) > 255 || preg_match('/[\x00-\x1f\x7f]/', $bytes) === 1) {
            throw new ProbeFailure('probe_target_invalid');
        }
        return chr(3) . chr(strlen($bytes)) . $bytes;
    }

    /** @param resource $stream */
    private static function writeAll($stream, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = @fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new ProbeFailure('probe_write_failed');
            }
            $offset += $written;
        }
    }

    /** @param resource $stream */
    private static function readExact($stream, int $length): string
    {
        $result = '';
        while (strlen($result) < $length) {
            $chunk = @fread($stream, $length - strlen($result));
            if (!is_string($chunk) || $chunk === '') {
                $meta = stream_get_meta_data($stream);
                throw new ProbeFailure(!empty($meta['timed_out']) ? 'probe_timeout' : 'probe_read_failed');
            }
            $result .= $chunk;
        }
        return $result;
    }

    /** @param resource $stream */
    private static function readDatagram($stream): string
    {
        $packet = @fread($stream, 65535);
        if (!is_string($packet) || $packet === '') {
            $meta = stream_get_meta_data($stream);
            throw new ProbeFailure(!empty($meta['timed_out']) ? 'probe_timeout' : 'udp_reply_empty');
        }
        return $packet;
    }

    private static function udpEnvelope(string $host, int $port, string $payload): string
    {
        return "\x00\x00\x00" . self::encodeAddress($host) . pack('n', $port) . $payload;
    }

    private static function isReservedResponse(string $packet): bool
    {
        return strlen($packet) >= 10
            && substr($packet, 0, 10) === "\x00\x00\x00\x01\x02\x00\x00\x00\x00\x00";
    }

    private static function formatAddress(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $host . ']' : $host;
    }

    private static function validHost(string $host): bool
    {
        return $host !== '' && strlen($host) <= 255 && preg_match('/[\x00-\x1f\x7f]/', $host) !== 1;
    }
}

final class ProbeFailure extends \RuntimeException
{
    public function __construct(public string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
