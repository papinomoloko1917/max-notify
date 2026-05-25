<?php

declare(strict_types=1);

namespace App\Profile;

use App\Camera\CameraSource;
use PDO;

final class ProfileRepository
{
    public function __construct(
        private PDO $pdo,
    ) {
    }

    public function clients(): array
    {
        return $this->pdo->query('SELECT id, name, max_chat_id FROM clients ORDER BY name')->fetchAll();
    }

    public function settings(): ProfileSettings
    {
        $rows = $this->pdo->query('SELECT name, value FROM profile_settings')->fetchAll();
        $settings = [];

        foreach ($rows as $row) {
            $settings[$row['name']] = $row['value'];
        }

        return new ProfileSettings(
            trim((string) ($settings['max_bot_token'] ?? $this->env('MAX_BOT_TOKEN'))),
            trim((string) ($settings['webhook_secret'] ?? $this->env('WEBHOOK_SECRET'))),
        );
    }

    public function updateSettings(string $maxBotToken, string $webhookSecret): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO profile_settings (name, value)
             VALUES (:name, :value)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );

        foreach ([
            'max_bot_token' => trim($maxBotToken),
            'webhook_secret' => trim($webhookSecret),
        ] as $name => $value) {
            $statement->execute([
                'name' => $name,
                'value' => $value,
            ]);
        }
    }

    public function cameras(): array
    {
        return $this->pdo->query(
            'SELECT c.id, c.source, c.label, c.snapshot_url, c.username, c.allowed_rules,
                    GROUP_CONCAT(cl.name ORDER BY cl.name SEPARATOR ", ") AS client_names,
                    GROUP_CONCAT(cl.max_chat_id ORDER BY cl.name SEPARATOR ",") AS max_chat_ids,
                    GROUP_CONCAT(cl.id ORDER BY cl.id SEPARATOR ",") AS client_ids
             FROM cameras c
             LEFT JOIN camera_clients cc ON cc.camera_id = c.id
             LEFT JOIN clients cl ON cl.id = cc.client_id
             GROUP BY c.id
             ORDER BY c.label'
        )->fetchAll();
    }

    public function addClient(string $name, string $maxChatId): void
    {
        $statement = $this->pdo->prepare('INSERT INTO clients (name, max_chat_id) VALUES (:name, :max_chat_id)');
        $statement->execute([
            'name' => $name,
            'max_chat_id' => $maxChatId,
        ]);
    }

    public function deleteClient(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM clients WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function updateClient(int $id, string $name, string $maxChatId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE clients SET name = :name, max_chat_id = :max_chat_id WHERE id = :id'
        );

        $statement->execute([
            'id' => $id,
            'name' => $name,
            'max_chat_id' => $maxChatId,
        ]);
    }

    public function addCamera(array $data, array $clientIds): void
    {
        $this->pdo->beginTransaction();

        $statement = $this->pdo->prepare(
            'INSERT INTO cameras (source, label, snapshot_url, username, password, allowed_rules)
             VALUES (:source, :label, :snapshot_url, :username, :password, :allowed_rules)'
        );

        $statement->execute([
            'source' => $data['source'],
            'label' => $data['label'],
            'snapshot_url' => $data['snapshot_url'],
            'username' => $data['username'],
            'password' => $data['password'],
            'allowed_rules' => $data['allowed_rules'] ?: null,
        ]);

        $cameraId = (int) $this->pdo->lastInsertId();
        $link = $this->pdo->prepare('INSERT INTO camera_clients (camera_id, client_id) VALUES (:camera_id, :client_id)');

        foreach ($clientIds as $clientId) {
            $link->execute([
                'camera_id' => $cameraId,
                'client_id' => (int) $clientId,
            ]);
        }

        $this->pdo->commit();
    }

    public function deleteCamera(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM cameras WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function updateCamera(int $id, array $data, array $clientIds): void
    {
        $this->pdo->beginTransaction();

        $fields = [
            'source = :source',
            'label = :label',
            'snapshot_url = :snapshot_url',
            'username = :username',
            'allowed_rules = :allowed_rules',
        ];

        $params = [
            'id' => $id,
            'source' => $data['source'],
            'label' => $data['label'],
            'snapshot_url' => $data['snapshot_url'],
            'username' => $data['username'],
            'allowed_rules' => $data['allowed_rules'] ?: null,
        ];

        if (($data['password'] ?? '') !== '') {
            $fields[] = 'password = :password';
            $params['password'] = $data['password'];
        }

        $statement = $this->pdo->prepare(
            'UPDATE cameras SET ' . implode(', ', $fields) . ' WHERE id = :id'
        );
        $statement->execute($params);

        $deleteLinks = $this->pdo->prepare('DELETE FROM camera_clients WHERE camera_id = :camera_id');
        $deleteLinks->execute(['camera_id' => $id]);

        $link = $this->pdo->prepare('INSERT INTO camera_clients (camera_id, client_id) VALUES (:camera_id, :client_id)');

        foreach ($clientIds as $clientId) {
            $link->execute([
                'camera_id' => $id,
                'client_id' => (int) $clientId,
            ]);
        }

        $this->pdo->commit();
    }

    public function cameraSources(): array
    {
        $rows = $this->pdo->query(
            'SELECT c.source, c.label, c.snapshot_url, c.username, c.password, c.allowed_rules,
                    GROUP_CONCAT(cl.max_chat_id ORDER BY cl.id SEPARATOR ",") AS max_chat_ids
             FROM cameras c
             INNER JOIN camera_clients cc ON cc.camera_id = c.id
             INNER JOIN clients cl ON cl.id = cc.client_id
             GROUP BY c.id'
        )->fetchAll();

        $sources = [];

        foreach ($rows as $row) {
            $chatIds = $this->csv($row['max_chat_ids'] ?? '');

            if ($chatIds === []) {
                continue;
            }

            $sources[$row['source']] = new CameraSource(
                $row['source'],
                $row['label'],
                $row['snapshot_url'],
                $row['username'],
                $row['password'],
                $chatIds,
                $this->csv($row['allowed_rules'] ?? ''),
            );
        }

        return $sources;
    }

    private function csv(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    private function env(string $name): string
    {
        $value = \getenv($name);

        return $value === false ? '' : $value;
    }
}
