<?php

declare(strict_types=1);

namespace App\Config;

final class AppConfig
{
    public function __construct(
        public readonly string $maxToken,
        public readonly string $maxChatId,
        public readonly string $cameraUrl,
        public readonly string $cameraUser,
        public readonly string $cameraPassword,
        public readonly string $webhookSecret,
        public readonly int $duplicateTtlSeconds,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            self::env('MAX_BOT_TOKEN'),
            self::env('MAX_CHAT_ID'),
            self::env('DAHUA_CAMERA_URL'),
            self::env('DAHUA_CAMERA_USER'),
            self::env('DAHUA_CAMERA_PASSWORD'),
            self::env('WEBHOOK_SECRET'),
            self::positiveIntEnv('DUPLICATE_TTL_SECONDS', 5),
        );
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        return $value === false ? '' : $value;
    }

    private static function positiveIntEnv(string $name, int $default): int
    {
        $value = getenv($name);

        if ($value === false || !ctype_digit($value)) {
            return $default;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $default;
    }

    public function missingValues(): array
    {
        $values = [
            'MAX_BOT_TOKEN' => $this->maxToken,
            'MAX_CHAT_ID' => $this->maxChatId,
            'DAHUA_CAMERA_URL' => $this->cameraUrl,
            'DAHUA_CAMERA_USER' => $this->cameraUser,
            'DAHUA_CAMERA_PASSWORD' => $this->cameraPassword,
            'WEBHOOK_SECRET' => $this->webhookSecret,
        ];

        $missing = [];

        foreach ($values as $name => $value) {
            if ($value === '') {
                $missing[] = $name;
            }
        }

        return $missing;
    }
}
