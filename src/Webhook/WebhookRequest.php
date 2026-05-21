<?php

declare(strict_types=1);

namespace App\Webhook;

final class WebhookRequest
{
    public function __construct(
        private array $server,
        private array $query,
        private array $post,
        private array $headers,
        private string $rawBody,
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self(
            $_SERVER,
            $_GET,
            $_POST,
            getallheaders(),
            file_get_contents('php://input') ?: '',
        );
    }

    public function eventName(): string
    {
        return $this->query['event'] ?? 'unknown';
    }

    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) ? $path : '/';
    }

    public function isHealthCheck(): bool
    {
        return $this->path() === '/health';
    }

    public function secret(): string
    {
        return $this->query['secret'] ?? '';
    }

    public function source(): string
    {
        return $this->sanitizeIdentifier(
            $this->query['source'] ?? $this->query['camera'] ?? 'default',
        );
    }

    public function rule(): string
    {
        return $this->sanitizeIdentifier($this->query['rule'] ?? 'unknown');
    }

    public function duplicateKey(): string
    {
        return implode(':', [
            $this->eventName(),
            $this->source(),
            $this->rule(),
        ]);
    }

    public function snapshotFilename(): string
    {
        return date('Ymd_His') . '_' . $this->source() . '_' . $this->rule() . '.jpg';
    }

    public function messageText(): string
    {
        return 'Dahua event: ' . $this->eventName()
            . ', source: ' . $this->source()
            . ', rule: ' . $this->rule();
    }

    public function toLogContext(): array
    {
        return [
            'time' => date('Y-m-d H:i:s'),
            'method' => $this->server['REQUEST_METHOD'] ?? null,
            'uri' => $this->safeUri(),
            'query' => $this->safeQuery(),
            'post' => $this->post,
            'headers' => $this->safeHeaders(),
            'raw_body_length' => strlen($this->rawBody),
            'parsed' => [
                'event' => $this->eventName(),
                'source' => $this->source(),
                'rule' => $this->rule(),
            ],
        ];
    }

    private function sanitizeIdentifier(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $value);
    }

    private function safeQuery(): array
    {
        $query = $this->query;

        if (isset($query['secret'])) {
            $query['secret'] = '[redacted]';
        }

        return $query;
    }

    private function safeUri(): ?string
    {
        $uri = $this->server['REQUEST_URI'] ?? null;

        if (!is_string($uri)) {
            return null;
        }

        $parts = parse_url($uri);

        if (!is_array($parts)) {
            return $uri;
        }

        $path = $parts['path'] ?? '';

        if (!isset($parts['query'])) {
            return $path;
        }

        parse_str($parts['query'], $query);

        if (isset($query['secret'])) {
            $query['secret'] = '[redacted]';
        }

        return $path . '?' . http_build_query($query);
    }

    private function safeHeaders(): array
    {
        $safeHeaders = [];

        foreach ($this->headers as $name => $value) {
            $lowerName = strtolower((string) $name);

            if (in_array($lowerName, ['authorization', 'cookie', 'set-cookie'], true)) {
                $safeHeaders[$name] = '[redacted]';
                continue;
            }

            $safeHeaders[$name] = $value;
        }

        return $safeHeaders;
    }
}
