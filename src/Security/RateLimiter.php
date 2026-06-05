<?php

namespace App\Security;

/** Limite le nombre de tentatives par IP pour éviter le brute force (login, contact, etc.) */
class RateLimiter
{
    private static string $storePath = __DIR__ . '/../../storage/rate_limit/';

    /** Vérifie si une action est permise (returns true si OK, false si limite dépassée).
        @param $key : identifiant unique (ex: "login_ip_192.168.1.1")
        @param $maxAttempts : nombre d'essais autorisés dans la fenêtre
        @param $windowSeconds : durée de la fenêtre en secondes */
    public static function checkLimit(string $key, int $maxAttempts = 5, int $windowSeconds = 900): bool
    {
        self::ensureStorageDir();

        $filename = self::$storePath . md5($key) . '.json';
        $now = time();

        // Lire les tentatives existantes
        $attempts = [];
        if (file_exists($filename)) {
            $data = json_decode(file_get_contents($filename), true);
            if (is_array($data)) {
                // Garder seulement les tentatives récentes
                $attempts = array_filter($data, fn($t) => $t > $now - $windowSeconds);
            }
        }

        // Vérifier si on a dépassé la limite
        if (count($attempts) >= $maxAttempts) {
            return false;
        }

        // Ajouter une nouvelle tentative
        $attempts[] = $now;
        file_put_contents($filename, json_encode(array_values($attempts)), LOCK_EX);

        return true;
    }

    /** Récupère le nombre de tentatives restantes */
    public static function getRemaining(string $key, int $maxAttempts = 5, int $windowSeconds = 900): int
    {
        self::ensureStorageDir();

        $filename = self::$storePath . md5($key) . '.json';
        $now = time();

        $attempts = [];
        if (file_exists($filename)) {
            $data = json_decode(file_get_contents($filename), true);
            if (is_array($data)) {
                $attempts = array_filter($data, fn($t) => $t > $now - $windowSeconds);
            }
        }

        return max(0, $maxAttempts - count($attempts));
    }

    /** Réinitialise les tentatives d'une clé */
    public static function reset(string $key): void
    {
        self::ensureStorageDir();

        $filename = self::$storePath . md5($key) . '.json';
        if (file_exists($filename)) {
            unlink($filename);
        }
    }

    private static function ensureStorageDir(): void
    {
        if (!is_dir(self::$storePath)) {
            mkdir(self::$storePath, 0775, true);
        }
    }
}
