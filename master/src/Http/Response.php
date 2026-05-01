<?php
declare(strict_types=1);

namespace Leikwan\Master\Http;

final class Response
{
    private int $status;
    /** @var array<string, string> */
    private array $headers;
    private string $body;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $status, array $headers, string $body)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
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

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
