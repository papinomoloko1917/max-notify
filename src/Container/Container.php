<?php

declare(strict_types=1);

namespace App\Container;

use App\Camera\DahuaCamera;
use App\Camera\CameraRegistry;
use App\Camera\CameraSource;
use App\Config\AppConfig;
use App\Event\DuplicateGuard;
use App\Event\TimeWindowFilter;
use App\Logger\WebhookLogger;
use App\Messenger\MaxMessenger;
use App\Webhook\EventMessageFormatter;

final class Container
{
    private ?AppConfig $config = null;
    private ?WebhookLogger $logger = null;
    private ?DuplicateGuard $duplicateGuard = null;
    private ?TimeWindowFilter $timeWindowFilter = null;
    private ?CameraRegistry $cameraRegistry = null;
    private ?MaxMessenger $maxMessenger = null;
    private ?EventMessageFormatter $eventMessageFormatter = null;

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

    public function timeWindowFilter(): TimeWindowFilter
    {
        $config = $this->config();

        return $this->timeWindowFilter ??= new TimeWindowFilter(
            $config->notifyAllowedFrom,
            $config->notifyAllowedTo,
        );
    }

    public function camera(string $source): DahuaCamera
    {
        $cameraSource = $this->cameraRegistry()->find($source);

        if ($cameraSource === null) {
            throw new \RuntimeException('Camera source was not found: ' . $source);
        }

        return new DahuaCamera(
            $cameraSource->snapshotUrl,
            $cameraSource->user,
            $cameraSource->password,
        );
    }

    public function cameraRegistry(): CameraRegistry
    {
        if ($this->cameraRegistry !== null) {
            return $this->cameraRegistry;
        }

        $sources = [];

        foreach ($this->config()->cameraSources() as $name => $sourceConfig) {
            $sources[$name] = new CameraSource(
                $name,
                $sourceConfig['label'],
                $sourceConfig['url'],
                $sourceConfig['user'],
                $sourceConfig['password'],
                $sourceConfig['max_chat_ids'],
                $sourceConfig['allowed_rules'],
            );
        }

        return $this->cameraRegistry = new CameraRegistry($sources);
    }

    public function maxMessenger(): MaxMessenger
    {
        $config = $this->config();

        return $this->maxMessenger ??= new MaxMessenger(
            $config->maxToken,
        );
    }

    public function eventMessageFormatter(): EventMessageFormatter
    {
        return $this->eventMessageFormatter ??= new EventMessageFormatter();
    }
}
