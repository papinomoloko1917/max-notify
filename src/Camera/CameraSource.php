<?php

declare(strict_types=1);

namespace App\Camera;

final class CameraSource
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $snapshotUrl,
        public readonly string $user,
        public readonly string $password,
        public readonly array $maxChatIds,
        public readonly array $allowedRules = [],
        public readonly string $notifyAllowedFrom = '',
        public readonly string $notifyAllowedTo = '',
        public readonly ?int $duplicateTtlSeconds = null,
    ) {
    }

    public function allowsRule(string $rule): bool
    {
        return $this->allowedRules === [] || in_array($rule, $this->allowedRules, true);
    }

}
