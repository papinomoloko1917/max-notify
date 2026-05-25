<?php

declare(strict_types=1);

namespace App\Config;

final class AppConfig
{
    public function __construct(
        public readonly string $mysqlHost,
        public readonly string $mysqlDatabase,
        public readonly string $mysqlUser,
        public readonly string $mysqlPassword,
        public readonly string $profileUsername,
        public readonly string $profilePasswordHash,
        public readonly int $duplicateTtlSeconds,
        public readonly string $notifyAllowedFrom,
        public readonly string $notifyAllowedTo,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            self::env('MYSQL_HOST') ?: 'mysql',
            self::env('MYSQL_DATABASE'),
            self::env('MYSQL_USER'),
            self::env('MYSQL_PASSWORD'),
            self::env('PROFILE_USERNAME'),
            self::env('PROFILE_PASSWORD_HASH'),
            self::positiveIntEnv('DUPLICATE_TTL_SECONDS', 5),
            self::env('NOTIFY_ALLOWED_FROM'),
            self::env('NOTIFY_ALLOWED_TO'),
        );
    }

    private static function env(string $name): string
    {
        $value = \getenv($name);

        return $value === false ? '' : $value;
    }

    private static function positiveIntEnv(string $name, int $default): int
    {
        $value = \getenv($name);

        if ($value === false || !\ctype_digit($value)) {
            return $default;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $default;
    }

    public function missingValues(): array
    {
        $values = [
            'MYSQL_DATABASE' => $this->mysqlDatabase,
            'MYSQL_USER' => $this->mysqlUser,
            'MYSQL_PASSWORD' => $this->mysqlPassword,
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
