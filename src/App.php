<?php

declare(strict_types=1);

namespace App;

use App\Container\Container;
use App\Event\TimeWindowFilter;
use App\Webhook\WebhookRequest;
use DateTimeImmutable;

final class App
{
    public function __construct(
        private Container $container,
    ) {
    }

    public function handle(): void
    {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');

        $logger = $this->container->logger();
        $config = $this->container->config();
        $request = WebhookRequest::fromGlobals();
        $event = $request->toLogContext();

        if ($request->isHealthCheck()) {
            echo 'OK';

            return;
        }

        if (str_starts_with($request->path(), '/profile')) {
            $this->container->profileController()->handle();

            return;
        }

        $missingConfig = $config->missingValues();

        if ($missingConfig !== []) {
            $event['error'] = [
                'message' => 'Missing config',
                'vars' => $missingConfig,
            ];

            $logger->write($event);

            http_response_code(500);
            echo 'Missing config';

            return;
        }

        $settings = $this->container->profileRepository()->settings();
        $missingSettings = $settings->missingValues();

        if ($missingSettings !== []) {
            $event['error'] = [
                'message' => 'Missing profile settings',
                'vars' => $missingSettings,
            ];

            $logger->write($event);

            http_response_code(500);
            echo 'Missing profile settings';

            return;
        }

        if (!hash_equals($settings->webhookSecret, $request->secret())) {
            $event['error'] = [
                'message' => 'Invalid webhook secret',
                'expected_length' => strlen($settings->webhookSecret),
                'received_length' => strlen($request->secret()),
                'secret_was_provided' => $request->secret() !== '',
            ];

            $logger->write($event);

            http_response_code(403);
            echo 'Forbidden';

            return;
        }

        $now = new DateTimeImmutable();
        $timeWindowFilter = $this->container->timeWindowFilter();
        $event['time_window'] = $timeWindowFilter->toLogContext($now);

        if (!$timeWindowFilter->isAllowed($now)) {
            $logger->write($event);

            echo 'OK time window skipped';

            return;
        }

        $snapshotFilename = $request->snapshotFilename();
        $cameraSource = $this->container->cameraRegistry()->find($request->source());

        if ($cameraSource === null) {
            $event['camera_source'] = [
                'requested' => $request->source(),
                'selected' => null,
                'is_unknown' => true,
            ];

            $logger->write($event);

            echo 'OK unknown source skipped';

            return;
        }

        $event['camera_source'] = [
            'requested' => $request->source(),
            'selected' => $cameraSource->name,
            'label' => $cameraSource->label,
            'is_unknown' => false,
            'chat_ids_count' => count($cameraSource->maxChatIds),
            'allowed_rules' => $cameraSource->allowedRules,
            'notify_allowed_from' => $cameraSource->notifyAllowedFrom,
            'notify_allowed_to' => $cameraSource->notifyAllowedTo,
            'duplicate_ttl_seconds' => $cameraSource->duplicateTtlSeconds,
        ];

        $duplicateTtlSeconds = $cameraSource->duplicateTtlSeconds ?? $config->duplicateTtlSeconds;
        $isDuplicate = $this->container->duplicateGuard()->isDuplicate($request->duplicateKey(), $duplicateTtlSeconds);

        $event['duplicate'] = [
            'key' => $request->duplicateKey(),
            'is_duplicate' => $isDuplicate,
            'ttl_seconds' => $duplicateTtlSeconds,
            'uses_camera_ttl' => $cameraSource->duplicateTtlSeconds !== null,
        ];

        if ($isDuplicate) {
            $logger->write($event);

            echo 'OK duplicate skipped';

            return;
        }

        $event['rule_filter'] = [
            'enabled' => $cameraSource->allowedRules !== [],
            'requested_rule' => $request->rule(),
            'allowed_rules' => $cameraSource->allowedRules,
            'is_allowed' => $cameraSource->allowsRule($request->rule()),
        ];

        if (!$cameraSource->allowsRule($request->rule())) {
            $logger->write($event);

            echo 'OK rule skipped';

            return;
        }

        $cameraTimeWindowFilter = new TimeWindowFilter(
            $cameraSource->notifyAllowedFrom,
            $cameraSource->notifyAllowedTo,
        );
        $event['camera_time_window'] = $cameraTimeWindowFilter->toLogContext($now);

        if (!$cameraTimeWindowFilter->isAllowed($now)) {
            $logger->write($event);

            echo 'OK camera time window skipped';

            return;
        }

        $snapshot = $this->container->camera($request->source())->getSnapshot();

        $event['snapshot'] = [
            'filename' => $snapshotFilename,
            'path' => null,
            'http_code' => $snapshot->httpCode,
            'error' => $snapshot->error,
        ];

        $imageToken = null;
        $max = $this->container->maxMessenger();

        if ($snapshot->isSuccessful()) {
            $imageUpload = $max->uploadImage($snapshot->image, $snapshotFilename);

            $event['max_upload'] = $imageUpload->uploadLogContext();
            $event['max_image_upload'] = $imageUpload->imageUploadLogContext();

            $imageToken = $imageUpload->token;
        } else {
            $event['max_upload'] = [
                'http_code' => null,
                'error' => 'Snapshot was not received',
                'has_url' => false,
            ];
            $event['max_image_upload'] = [
                'http_code' => null,
                'error' => 'Snapshot was not received',
                'has_token' => false,
            ];
        }

        $messageText = $this->container->eventMessageFormatter()->format($request, $cameraSource->label);
        $event['message_text'] = $messageText;
        $event['max'] = [];

        foreach ($cameraSource->maxChatIds as $chatId) {
            $event['max'][] = $max->sendMessage($messageText, $imageToken, $chatId)->toLogContext();
        }

        $logger->write($event);

        echo 'OK';
    }
}
