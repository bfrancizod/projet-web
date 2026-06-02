<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

/**
 * Classe Database — Connexion PDO en Singleton
 *
 * Garantit une seule instance de connexion à la base de données
 * pendant toute la durée d'une requête HTTP. Les paramètres de
 * connexion sont lus depuis les variables d'environnement (.env).
 */
class Database
{
    /** @var PDO|null Instance unique de la connexion PDO */
    private static ?PDO $instance = null;

    /**
     * Retourne la connexion PDO, en la créant si elle n'existe pas encore.
     *
     * Configuration PDO :
     * - ERRMODE_EXCEPTION : toute erreur SQL lève une exception (évite les erreurs silencieuses)
     * - FETCH_ASSOC : les résultats sont retournés sous forme de tableaux associatifs
     * - EMULATE_PREPARES false : utilise les vraies requêtes préparées MySQL (protection injection SQL)
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            try {
                // Lecture des paramètres depuis les variables d'environnement
                $host   = $_ENV['DB_HOST'] ?? '';
                $port   = $_ENV['DB_PORT'] ?? '';
                $dbname = $_ENV['DB_NAME'] ?? '';
                $user   = $_ENV['DB_USER'] ?? '';
                $pass   = $_ENV['DB_PASS'] ?? '';

                // Vérification que toutes les variables sont bien définies dans .env
                if ($host === '' || $port === '' || $dbname === '' || $user === '' || $pass === '') {
                    throw new PDOException('Variables de connexion BDD manquantes dans le fichier .env');
                }

                // Construction du DSN avec charset UTF-8 pour supporter les caractères spéciaux
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $host,
                    $port,
                    $dbname
                );

                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // Exceptions sur erreur SQL
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Tableaux associatifs par défaut
                    PDO::ATTR_EMULATE_PREPARES   => false,                   // Vraies requêtes préparées
                ]);

                self::$instance = $pdo;
            } catch (PDOException $e) {
                // On logue l'erreur réelle en interne sans l'exposer à l'utilisateur
                error_log('[Database] Erreur connexion : ' . $e->getMessage());
                http_response_code(500);
                die('Une erreur technique est survenue. Veuillez réessayer plus tard.');
            }
        }

        return self::$instance;
    }
}