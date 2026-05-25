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
        $normalizedSources = [];

        foreach ($sources as $source => $cameraSource) {
            $normalizedSources[strtolower((string) $source)] = $cameraSource;
        }

        $this->sources = $normalizedSources;
    }

    public function find(string $source): ?CameraSource
    {
        return $this->sources[strtolower($source)] ?? null;
    }
}
