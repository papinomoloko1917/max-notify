<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final class Database
{
    public function __construct(
        private string $host,
        private string $database,
        private string $user,
        private string $password,
    ) {
    }

    public function pdo(): PDO
    {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $this->host, $this->database);

        return new PDO($dsn, $this->user, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
