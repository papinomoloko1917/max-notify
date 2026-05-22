<?php

declare(strict_types=1);

namespace App\Config;

final class AppConfig
{
    public function __construct(
        public readonly string $maxToken,
        public readonly string $webhookSecret,
        public readonly int $duplicateTtlSeconds,
        public readonly string $notifyAllowedFrom,
        public readonly string $notifyAllowedTo,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            self::env('MAX_BOT_TOKEN'),
            self::env('WEBHOOK_SECRET'),
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
            'MAX_BOT_TOKEN' => $this->maxToken,
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

    public function cameraSources(): array
    {
        $sources = [];
        $sourceNames = \array_filter(\array_map('trim', \explode(',', self::env('CAMERA_SOURCES'))));

        foreach ($sourceNames as $sourceName) {
            $key = \strtoupper(\preg_replace('/[^a-zA-Z0-9]/', '_', $sourceName));
            $prefix = 'CAMERA_' . $key;

            $url = self::env($prefix . '_URL');
            $label = self::env($prefix . '_LABEL') ?: $sourceName;
            $user = self::env($prefix . '_USER');
            $password = self::env($prefix . '_PASSWORD');
            $maxChatIds = self::chatIdsEnv($prefix . '_MAX_CHAT_IDS', self::env($prefix . '_MAX_CHAT_ID'));

            $allowedRules = self::csvEnv($prefix . '_ALLOWED_RULES');

            if ($url === '' || $user === '' || $password === '' || $maxChatIds === []) {
                continue;
            }

            $sources[$sourceName] = [
                'url' => $url,
                'label' => $label,
                'user' => $user,
                'password' => $password,
                'max_chat_ids' => $maxChatIds,
                'allowed_rules' => $allowedRules,
            ];
        }

        return $sources;
    }

    private static function csvEnv(string $name): array
    {
        return \array_values(\array_filter(
            \array_map('trim', \explode(',', self::env($name))),
            static fn(string $value): bool => $value !== '',
        ));
    }

    private static function chatIdsEnv(string $multiName, string $singleValue = ''): array
    {
        $chatIds = self::csvEnv($multiName);

        if ($chatIds !== []) {
            return $chatIds;
        }

        $singleValue = \trim($singleValue);

        return $singleValue === '' ? [] : [$singleValue];
    }
}
