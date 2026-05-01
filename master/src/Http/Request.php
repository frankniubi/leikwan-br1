<?php
declare(strict_types=1);

namespace Leikwan\Master\Http;

final class Request
{
    private string $method;
    private string $path;
    /** @var array<string, mixed> */
    private array $query;
    /** @var array<string, mixed> */
    private array $body;
    /** @var array<string, string> */
    private array $headers;
    private string $rawBody;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function __construct(string $method, string $path, array $query, array $body, array $headers, string $rawBody)
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->query = $query;
        $this->body = $body;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->rawBody = $rawBody;
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $query = $_GET ?? [];
        $rawBody = file_get_contents('php://input') ?: '';
        $body = $_POST ?? [];
        $headers = function_exists('getallheaders') ? getallheaders() : self::serverHeaders();

        $contentType = strtolower((string) ($headers['Content-Type'] ?? $headers['content-type'] ?? ''));
        if (str_contains($contentType, 'application/json') && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return new self($method, $path, $query, $body, $headers, $rawBody);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth === null) {
            return null;
        }

        if (preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function serverHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $header = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$header] = (string) $value;
        }
        return $headers;
    }
}
