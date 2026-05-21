<?php

declare(strict_types=1);

namespace App\Event;

final class DuplicateGuard
{
    public function __construct(
        private string $stateFile,
        private int $ttlSeconds,
    ) {
    }

    public function isDuplicate(string $key): bool
    {
        $now = time();
        $state = $this->readState();
        $lastSeenAt = $state[$key] ?? null;

        $state[$key] = $now;
        $this->writeState($state, $now);

        return is_int($lastSeenAt) && ($now - $lastSeenAt) < $this->ttlSeconds;
    }

    private function readState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }

        $json = file_get_contents($this->stateFile);
        $state = json_decode($json ?: '', true);

        return is_array($state) ? $state : [];
    }

    private function writeState(array $state, int $now): void
    {
        $freshState = [];

        foreach ($state as $key => $lastSeenAt) {
            if (is_int($lastSeenAt) && ($now - $lastSeenAt) < $this->ttlSeconds) {
                $freshState[$key] = $lastSeenAt;
            }
        }

        file_put_contents(
            $this->stateFile,
            json_encode($freshState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
