<?php

namespace App;

use PDO;
use PDOException;

class Database
{
    public static function getConnection(): PDO
    {
        try {
            $pdo = new PDO(
                "mysql:host=127.0.0.1;dbname=help_me_stage;charset=utf8mb4",
                "helpmeuser",
                "motdepasse123"
            );

            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            return $pdo;
        } catch (PDOException $e) {
            die("Erreur connexion DB : " . $e->getMessage());
        }
    }
}