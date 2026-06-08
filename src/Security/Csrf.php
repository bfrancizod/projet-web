<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Classe Csrf — Protection contre les attaques Cross-Site Request Forgery
 *
 * Cette classe
 * génère un token unique par session, inclus dans chaque formulaire POST,
 * et vérifie sa validité avant tout traitement.
 */
final class Csrf
{
    /** Clé utilisée pour stocker le token CSRF dans la session */
    private const SESSION_KEY = '_csrf_token';

    /**
     * Retourne le token CSRF de la session, en le générant si absent.
     *
     * bin2hex(random_bytes(32)) produit 64 caractères hexadécimaux
     * aléatoires cryptographiquement sûrs (256 bits d'entropie).
     */
    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Vérifie que le token soumis correspond au token de session.
     *
     * hash_equals() est utilisé à la place de === pour éviter les
     * attaques temporelles (timing attacks) : la comparaison prend
     * toujours le même temps quelle que soit la valeur comparée.
     */
    public static function validate(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        $sessionToken = $_SESSION[self::SESSION_KEY] ?? '';

        // Comparaison résistante aux timing attacks
        return is_string($sessionToken) && hash_equals($sessionToken, $token);
    }

    /**
     * Exige un token valide et coupe la requête en 403 si invalide.
     * À appeler en début de traitement de tout formulaire POST.
     */
    public static function requireValidToken(?string $token): void
    {
        if (!self::validate($token)) {
            http_response_code(403);
            exit('Jeton CSRF invalide.');
        }
    }

    /**
     * Régénère un nouveau token CSRF.
     */
    public static function rotate(): void
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }
}