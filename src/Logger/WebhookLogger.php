<?php

declare(strict_types=1);

namespace App\Logger;

final class WebhookLogger
{
    public function __construct(
        private string $logFile,
    ) {
    }

    public function write(array $event): void
    {
        file_put_contents(
            $this->logFile,
            json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL . str_repeat('-', 80) . PHP_EOL,
            FILE_APPEND
        );
    }
}
