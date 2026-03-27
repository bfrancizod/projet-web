<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null; // création du singleton

    public static function getConnection(): PDO
    {
        if (self::$instance === null) { //crée l'instance une seule fois
            try {
                $host = $_ENV['DB_HOST'] ?? '';
                $port = $_ENV['DB_PORT'] ?? '';
                $dbname = $_ENV['DB_NAME'] ?? '';
                $user = $_ENV['DB_USER'] ?? '';
                $pass = $_ENV['DB_PASS'] ?? '';

                if (!$host || !$port || !$dbname || !$user || !$pass) {
                    die('Erreur : variables .env manquantes');
                }

                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

                $pdo = new PDO($dsn, $user, $pass);

                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                self::$instance = $pdo; // singleton

            } catch (PDOException $e) {
                die('Erreur connexion DB : ' . $e->getMessage());
            }

        }

        return self::$instance; // si l'instance existe déjà
    }
}