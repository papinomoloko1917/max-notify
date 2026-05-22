<?php

declare(strict_types=1);

namespace App\Event;

use DateTimeImmutable;

final class TimeWindowFilter
{
    private const TIME_PATTERN = '/^\d{2}:\d{2}$/';

    public function __construct(
        private string $allowedFrom,
        private string $allowedTo,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->allowedFrom !== '' || $this->allowedTo !== '';
    }

    public function isAllowed(DateTimeImmutable $now): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        if (!$this->hasValidConfig()) {
            return false;
        }

        $currentMinute = $this->minutesFromMidnight($now->format('H:i'));
        $fromMinute = $this->minutesFromMidnight($this->allowedFrom);
        $toMinute = $this->minutesFromMidnight($this->allowedTo);

        if ($fromMinute === $toMinute) {
            return true;
        }

        if ($fromMinute < $toMinute) {
            return $currentMinute >= $fromMinute && $currentMinute <= $toMinute;
        }

        return $currentMinute >= $fromMinute || $currentMinute <= $toMinute;
    }

    public function toLogContext(DateTimeImmutable $now): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'allowed_from' => $this->allowedFrom,
            'allowed_to' => $this->allowedTo,
            'current_time' => $now->format('H:i'),
            'is_allowed' => $this->isAllowed($now),
            'is_config_valid' => $this->hasValidConfig(),
        ];
    }

    private function hasValidConfig(): bool
    {
        return $this->isValidTime($this->allowedFrom) && $this->isValidTime($this->allowedTo);
    }

    private function isValidTime(string $time): bool
    {
        if (!preg_match(self::TIME_PATTERN, $time)) {
            return false;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59;
    }

    private function minutesFromMidnight(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }
}
