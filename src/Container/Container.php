<?php

declare(strict_types=1);

namespace App\Container;

use App\Camera\DahuaCamera;
use App\Config\AppConfig;
use App\Event\DuplicateGuard;
use App\Logger\WebhookLogger;
use App\Messenger\MaxMessenger;

final class Container
{
    private ?AppConfig $config = null;
    private ?WebhookLogger $logger = null;
    private ?DuplicateGuard $duplicateGuard = null;
    private ?DahuaCamera $camera = null;
    private ?MaxMessenger $maxMessenger = null;

    public function __construct(
        private string $appPath,
    ) {
    }

    public function config(): AppConfig
    {
        return $this->config ??= AppConfig::fromEnv();
    }

    public function logger(): WebhookLogger
    {
        return $this->logger ??= new WebhookLogger($this->appPath . '/storage/logs/webhook.log');
    }

    public function duplicateGuard(): DuplicateGuard
    {
        return $this->duplicateGuard ??= new DuplicateGuard(
            $this->appPath . '/storage/logs/duplicate-events.json',
            $this->config()->duplicateTtlSeconds,
        );
    }

    public function camera(): DahuaCamera
    {
        $config = $this->config();

        return $this->camera ??= new DahuaCamera(
            $config->cameraUrl,
            $config->cameraUser,
            $config->cameraPassword,
        );
    }

    public function maxMessenger(): MaxMessenger
    {
        $config = $this->config();

        return $this->maxMessenger ??= new MaxMessenger(
            $config->maxToken,
            $config->maxChatId,
        );
    }
}
