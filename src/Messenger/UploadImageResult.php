<?php

declare(strict_types=1);

namespace App\Messenger;

final class UploadImageResult
{
    public function __construct(
        public readonly CreateUploadResult $createUpload,
        public readonly ?string $token,
        public readonly ?int $httpCode,
        public readonly ?string $error,
    ) {
    }

    public function uploadLogContext(): array
    {
        return $this->createUpload->toLogContext();
    }

    public function imageUploadLogContext(): array
    {
        return [
            'http_code' => $this->httpCode,
            'error' => $this->error,
            'has_token' => $this->token !== null,
        ];
    }
}
