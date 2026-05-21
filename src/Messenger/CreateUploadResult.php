<?php

declare(strict_types=1);

namespace App\Messenger;

final class CreateUploadResult
{
    public function __construct(
        public readonly ?string $url,
        public readonly int $httpCode,
        public readonly ?string $error,
    ) {
    }

    public function toLogContext(): array
    {
        return [
            'http_code' => $this->httpCode,
            'error' => $this->error,
            'has_url' => $this->url !== null,
        ];
    }
}
