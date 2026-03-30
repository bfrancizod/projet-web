<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            try {
                $host = $_ENV['DB_HOST'] ?? '';
                $port = $_ENV['DB_PORT'] ?? '';
                $dbname = $_ENV['DB_NAME'] ?? '';
                $user = $_ENV['DB_USER'] ?? '';
                $pass = $_ENV['DB_PASS'] ?? '';

                if ($host === '' || $port === '' || $dbname === '' || $user === '' || $pass === '') {
                    throw new PDOException('Variables de connexion BDD manquantes dans le fichier .env');
                }

                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $host,
                    $port,
                    $dbname
                );

                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                self::$instance = $pdo;
            } catch (PDOException $e) {
                die('Erreur connexion DB : ' . $e->getMessage());
            }
        }

        return self::$instance;
    }
}