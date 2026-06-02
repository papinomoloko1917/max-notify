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

    public function isDuplicate(string $key, ?int $ttlSeconds = null): bool
    {
        $now = time();
        $ttlSeconds = $ttlSeconds !== null && $ttlSeconds > 0 ? $ttlSeconds : $this->ttlSeconds;
        $handle = fopen($this->stateFile, 'c+');

        if ($handle === false) {
            return false;
        }

        flock($handle, LOCK_EX);

        rewind($handle);

        $json = stream_get_contents($handle);
        $state = json_decode($json ?: '', true);
        $state = is_array($state) ? $state : [];

        $lastSeenAt = $this->lastSeenAt($state[$key] ?? null);
        $isDuplicate = $lastSeenAt !== null && ($now - $lastSeenAt) < $ttlSeconds;

        $state[$key] = [
            'last_seen_at' => $now,
            'ttl_seconds' => $ttlSeconds,
        ];
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

        foreach ($state as $key => $entry) {
            $lastSeenAt = $this->lastSeenAt($entry);
            $ttlSeconds = $this->ttlSeconds($entry);

            if ($lastSeenAt !== null && ($now - $lastSeenAt) < $ttlSeconds) {
                $freshState[$key] = [
                    'last_seen_at' => $lastSeenAt,
                    'ttl_seconds' => $ttlSeconds,
                ];
            }
        }

        return $freshState;
    }

    private function lastSeenAt(mixed $entry): ?int
    {
        if (is_int($entry)) {
            return $entry;
        }

        if (is_array($entry) && isset($entry['last_seen_at']) && is_int($entry['last_seen_at'])) {
            return $entry['last_seen_at'];
        }

        return null;
    }

    private function ttlSeconds(mixed $entry): int
    {
        if (is_array($entry) && isset($entry['ttl_seconds']) && is_int($entry['ttl_seconds']) && $entry['ttl_seconds'] > 0) {
            return $entry['ttl_seconds'];
        }

        return $this->ttlSeconds;
    }
}
