<?php

declare(strict_types=1);

namespace App\Container;

use App\Camera\DahuaCamera;
use App\Camera\CameraRegistry;
use App\Config\AppConfig;
use App\Database\Database;
use App\Event\DuplicateGuard;
use App\Event\TimeWindowFilter;
use App\Logger\WebhookLogger;
use App\Messenger\MaxMessenger;
use App\Profile\ProfileController;
use App\Profile\ProfileRepository;
use App\Profile\ProfileSchema;
use App\Webhook\EventMessageFormatter;

final class Container
{
    private ?AppConfig $config = null;
    private ?\PDO $pdo = null;
    private ?WebhookLogger $logger = null;
    private ?DuplicateGuard $duplicateGuard = null;
    private ?TimeWindowFilter $timeWindowFilter = null;
    private ?CameraRegistry $cameraRegistry = null;
    private ?MaxMessenger $maxMessenger = null;
    private ?EventMessageFormatter $eventMessageFormatter = null;
    private ?ProfileRepository $profileRepository = null;
    private bool $profileMigrated = false;

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

    public function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $config = $this->config();
        $database = new Database(
            $config->mysqlHost,
            $config->mysqlDatabase,
            $config->mysqlUser,
            $config->mysqlPassword,
        );

        return $this->pdo = $database->pdo();
    }

    public function profileRepository(): ProfileRepository
    {
        $this->migrateProfile();

        return $this->profileRepository ??= new ProfileRepository($this->pdo());
    }

    public function profileController(): ProfileController
    {
        $config = $this->config();

        return new ProfileController(
            $this->profileRepository(),
            $config->profileUsername,
            $config->profilePasswordHash,
            $this->appPath . '/resources/views',
        );
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

        return $this->cameraRegistry = new CameraRegistry($this->profileRepository()->cameraSources());
    }

    public function maxMessenger(): MaxMessenger
    {
        return $this->maxMessenger ??= new MaxMessenger(
            $this->profileRepository()->settings()->maxBotToken,
        );
    }

    public function eventMessageFormatter(): EventMessageFormatter
    {
        return $this->eventMessageFormatter ??= new EventMessageFormatter();
    }

    private function migrateProfile(): void
    {
        if ($this->profileMigrated) {
            return;
        }

        (new ProfileSchema($this->pdo()))->migrate();
        $this->profileMigrated = true;
    }
}
