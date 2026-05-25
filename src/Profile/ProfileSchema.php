<?php

declare(strict_types=1);

namespace App\Profile;

use PDO;

final class ProfileSchema
{
    public function __construct(
        private PDO $pdo,
    ) {
    }

    public function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS profile_settings (
                name VARCHAR(80) NOT NULL PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS clients (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                max_chat_id VARCHAR(64) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_clients_max_chat_id (max_chat_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS cameras (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source VARCHAR(64) NOT NULL,
                label VARCHAR(120) NOT NULL,
                snapshot_url VARCHAR(512) NOT NULL,
                username VARCHAR(120) NOT NULL,
                password VARCHAR(255) NOT NULL,
                allowed_rules VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_cameras_source (source)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS camera_clients (
                camera_id INT UNSIGNED NOT NULL,
                client_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (camera_id, client_id),
                CONSTRAINT fk_camera_clients_camera
                    FOREIGN KEY (camera_id) REFERENCES cameras(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_camera_clients_client
                    FOREIGN KEY (client_id) REFERENCES clients(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
