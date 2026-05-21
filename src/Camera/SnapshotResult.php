<?php

declare(strict_types=1);

namespace App\Camera;

final class SnapshotResult
{
    public function __construct(
        public readonly ?string $image,
        public readonly int $httpCode,
        public readonly ?string $error,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->httpCode === 200 && $this->image !== null;
    }
}
