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
    private const AUTH_USERNAME_PASSWORD = 2;
    private const DEFAULT_TIMEOUT_SECONDS = 8;
    private const UDP_PROBE_HOST = '1.1.1.1';
    private const UDP_PROBE_PORT = 53;

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
            $stream = self::openTcp($host, $port);
            try {
                self::authenticate($stream, $user, $password);
                self::sendCommand($stream, self::CMD_CONNECT, $targetHost, $targetPort);
                $reply = self::readReply($stream);
                if ($reply['code'] !== 0) {
                    return new NativeSocks5ProbeResult(false, false, false, 'tcp_connect_rejected');
                }
                $tcpSucceeded = true;
            } finally {
                fclose($stream);
            }
        } catch (ProbeFailure $failure) {
            return new NativeSocks5ProbeResult(false, false, false, $failure->errorCode);
        } catch (Throwable) {
            return new NativeSocks5ProbeResult(false, false, false, 'tcp_probe_failed');
        }

        $udpSucceeded = false;
        try {
            $control = self::openTcp($host, $port);
            try {
                self::authenticate($control, $user, $password);
                self::sendCommand($control, self::CMD_UDP_ASSOCIATE, '0.0.0.0', 0);
                $reply = self::readReply($control);
                if ($reply['code'] !== 0 || $reply['port'] < 1 || $reply['port'] > 65535) {
                    return new NativeSocks5ProbeResult(false, $tcpSucceeded, false, 'udp_associate_rejected');
                }
                $relayHost = self::relayHost($reply['host'], $host);
                $udp = self::openUdp($relayHost, $reply['port']);
                try {
                    $query = self::dnsQuery();
                    self::writeAll($udp, self::udpEnvelope(self::UDP_PROBE_HOST, self::UDP_PROBE_PORT, $query));
                    $response = self::readDatagram($udp);
                    self::assertDnsResponse($response, $query);
                    $udpSucceeded = true;
                } finally {
                    fclose($udp);
                }
            } finally {
                fclose($control);
            }
        } catch (ProbeFailure $failure) {
            return new NativeSocks5ProbeResult(false, $tcpSucceeded, false, $failure->errorCode);
        } catch (Throwable) {
            return new NativeSocks5ProbeResult(false, $tcpSucceeded, false, 'udp_probe_failed');
        }

        return new NativeSocks5ProbeResult($tcpSucceeded && $udpSucceeded, $tcpSucceeded, $udpSucceeded, '');
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

    /** @param resource $stream */
    private static function authenticate($stream, string $user, string $password): void
    {
        self::writeAll($stream, chr(self::VERSION) . chr(1) . chr(self::AUTH_USERNAME_PASSWORD));
        $reply = self::readExact($stream, 2);
        if (ord($reply[0]) !== self::VERSION || ord($reply[1]) !== self::AUTH_USERNAME_PASSWORD) {
            throw new ProbeFailure('socks5_auth_method_rejected');
        }
        self::writeAll($stream, chr(1) . chr(strlen($user)) . $user . chr(strlen($password)) . $password);
        $authReply = self::readExact($stream, 2);
        if (ord($authReply[0]) !== 1 || ord($authReply[1]) !== 0) {
            throw new ProbeFailure('socks5_auth_failed');
        }
    }

    /** @param resource $stream */
    private static function sendCommand($stream, int $command, string $host, int $port): void
    {
        self::writeAll($stream, chr(self::VERSION) . chr($command) . chr(0) . self::encodeAddress($host) . pack('n', $port));
    }

    /** @param resource $stream @return array{code:int,host:string,port:int} */
    private static function readReply($stream): array
    {
        $header = self::readExact($stream, 4);
        if (ord($header[0]) !== self::VERSION || ord($header[2]) !== 0) {
            throw new ProbeFailure('socks5_reply_invalid');
        }
        $host = self::readAddress($stream, ord($header[3]));
        $portBytes = self::readExact($stream, 2);
        return [
            'code' => ord($header[1]),
            'host' => $host,
            'port' => unpack('n', $portBytes)[1],
        ];
    }

    /** @param resource $stream */
    private static function readAddress($stream, int $type): string
    {
        return match ($type) {
            1 => inet_ntop(self::readExact($stream, 4)) ?: '',
            3 => self::readDomain($stream),
            4 => inet_ntop(self::readExact($stream, 16)) ?: '',
            default => throw new ProbeFailure('socks5_address_invalid'),
        };
    }

    /** @param resource $stream */
    private static function readDomain($stream): string
    {
        $length = ord(self::readExact($stream, 1));
        if ($length < 1 || $length > 255) {
            throw new ProbeFailure('socks5_address_invalid');
        }
        return self::readExact($stream, $length);
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

    private static function dnsQuery(): string
    {
        $id = random_bytes(2);
        return $id . "\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00"
            . "\x07example\x03com\x00\x00\x01\x00\x01";
    }

    private static function assertDnsResponse(string $packet, string $query): void
    {
        if (strlen($packet) < 10 || substr($packet, 0, 3) !== "\x00\x00\x00") {
            throw new ProbeFailure('udp_reply_invalid');
        }
        $offset = 3;
        $type = ord($packet[$offset++]);
        if ($type === 1) {
            $offset += 4;
        } elseif ($type === 4) {
            $offset += 16;
        } elseif ($type === 3 && $offset < strlen($packet)) {
            $length = ord($packet[$offset]);
            $offset += 1 + $length;
        } else {
            throw new ProbeFailure('udp_reply_invalid');
        }
        if ($offset + 2 > strlen($packet)) {
            throw new ProbeFailure('udp_reply_invalid');
        }
        $offset += 2;
        $dns = substr($packet, $offset);
        if (strlen($dns) < 2 || !hash_equals(substr($query, 0, 2), substr($dns, 0, 2))) {
            throw new ProbeFailure('udp_dns_id_mismatch');
        }
    }

    private static function relayHost(string $replyHost, string $proxyHost): string
    {
        if ($replyHost === '' || $replyHost === '0.0.0.0' || $replyHost === '::') {
            return $proxyHost;
        }
        return $replyHost;
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
