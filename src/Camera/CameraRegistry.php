<?php

declare(strict_types=1);

namespace App\Camera;

final class CameraRegistry
{
    /**
     * @param array<string, CameraSource> $sources
     */
    public function __construct(
        private array $sources,
    ) {
    }

    public function find(string $source): ?CameraSource
    {
        return $this->sources[$source] ?? null;
    }
}
