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
        $handle = fopen($this->stateFile, 'c+');

        if ($handle === false) {
            return false;
        }

        flock($handle, LOCK_EX);

        rewind($handle);

        $json = stream_get_contents($handle);
        $state = json_decode($json ?: '', true);
        $state = is_array($state) ? $state : [];

        $lastSeenAt = $state[$key] ?? null;
        $isDuplicate = is_int($lastSeenAt) && ($now - $lastSeenAt) < $this->ttlSeconds;

        $state[$key] = $now;
        $freshState = $this->freshState($state, $now);

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($freshState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $isDuplicate;
    }

    private function freshState(array $state, int $now): array
    {
        $freshState = [];

        foreach ($state as $key => $lastSeenAt) {
            if (is_int($lastSeenAt) && ($now - $lastSeenAt) < $this->ttlSeconds) {
                $freshState[$key] = $lastSeenAt;
            }
        }

        return $freshState;
    }
}
