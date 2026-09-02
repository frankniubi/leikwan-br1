<?php
declare(strict_types=1);

namespace Leikwan\Master\Http;

final class Response
{
    private int $status;
    /** @var array<string, string> */
    private array $headers;
    private string $body;
    /** @var (\Closure(): void)|null */
    private ?\Closure $streamBody;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $status, array $headers, string $body, ?\Closure $streamBody = null)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
        $this->streamBody = $streamBody;
    }

    /**
     * Creates a response whose payload is emitted in bounded chunks at send
     * time. The normal body accessors remain string-based for small responses.
     */
    public static function streamed(int $status, array $headers, callable $streamer): self
    {
        return new self($status, $headers, '', \Closure::fromCallable($streamer));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(int $status, array $payload): self
    {
        return new self($status, ['Content-Type' => 'application/json; charset=utf-8'], (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    public static function html(int $status, string $html): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    public static function text(int $status, string $text, string $contentType = 'text/plain; charset=utf-8'): self
    {
        return new self($status, ['Content-Type' => $contentType], $text);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, ['Location' => $location], '');
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self(
            $this->status,
            array_merge($this->headers, $headers),
            $this->body,
            $this->streamBody,
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isStreamed(): bool
    {
        return $this->streamBody !== null;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->streamBody !== null) {
            ($this->streamBody)();
            return;
        }
        echo $this->body;
    }
}
