<?php

declare(strict_types=1);

namespace App\Profile;

final class ProfileSettings
{
    public function __construct(
        public readonly string $maxBotToken,
        public readonly string $webhookSecret,
    ) {
    }

    public function missingValues(): array
    {
        $missing = [];

        if ($this->maxBotToken === '') {
            $missing[] = 'MAX bot token';
        }

        if ($this->webhookSecret === '') {
            $missing[] = 'Webhook secret';
        }

        return $missing;
    }
}
