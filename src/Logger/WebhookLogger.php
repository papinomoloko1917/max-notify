<?php

declare(strict_types=1);

namespace App\Logger;

final class WebhookLogger
{
    private const MAX_ENTRIES = 40;
    private const SEPARATOR = '--------------------------------------------------------------------------------';

    public function __construct(
        private string $logFile,
    ) {
    }

    public function write(array $event): void
    {
        $entry = json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($entry === false) {
            $entry = json_encode([
                'time' => date('Y-m-d H:i:s'),
                'error' => 'Failed to encode log event',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        $handle = fopen($this->logFile, 'c+');

        if ($handle === false) {
            return;
        }

        flock($handle, LOCK_EX);
        rewind($handle);

        $currentContent = stream_get_contents($handle) ?: '';
        $entries = $this->parseEntries($currentContent);
        $entries[] = $entry;
        $entries = array_slice($entries, -self::MAX_ENTRIES);

        $newContent = implode(PHP_EOL . self::SEPARATOR . PHP_EOL, $entries)
            . PHP_EOL . self::SEPARATOR . PHP_EOL;

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $newContent);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function parseEntries(string $content): array
    {
        $parts = explode(self::SEPARATOR, $content);
        $entries = [];

        foreach ($parts as $part) {
            $entry = trim($part);

            if ($entry !== '') {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
