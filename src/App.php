<?php

declare(strict_types=1);

namespace App;

use App\Container\Container;
use App\Webhook\WebhookRequest;

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

        if (!hash_equals($config->webhookSecret, $request->secret())) {
            $event['error'] = [
                'message' => 'Invalid webhook secret',
                'expected_length' => strlen($config->webhookSecret),
                'received_length' => strlen($request->secret()),
                'secret_was_provided' => $request->secret() !== '',
            ];

            $logger->write($event);

            http_response_code(403);
            echo 'Forbidden';

            return;
        }

        $isDuplicate = $this->container->duplicateGuard()->isDuplicate($request->duplicateKey());

        $event['duplicate'] = [
            'key' => $request->duplicateKey(),
            'is_duplicate' => $isDuplicate,
            'ttl_seconds' => $config->duplicateTtlSeconds,
        ];

        if ($isDuplicate) {
            $logger->write($event);

            echo 'OK duplicate skipped';

            return;
        }

        $snapshotFilename = $request->snapshotFilename();
        $snapshot = $this->container->camera()->getSnapshot();

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

        $event['max'] = $max->sendMessage($request->messageText(), $imageToken)->toLogContext();

        $logger->write($event);

        echo 'OK';
    }
}
