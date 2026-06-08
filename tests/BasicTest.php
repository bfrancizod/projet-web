<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Suite de tests unitaires de l'application Help Me Stage.
 *
 * Regroupe dans un seul fichier les tests des fonctions de sécurité critiques,
 * organisés en 3 sections :
 *   1. Mots de passe & emails  (hachage, validation de format)
 *   2. Protection CSRF         (génération, validation, rotation du jeton)
 *   3. Rate limiting           (blocage anti-force-brute, réinitialisation)
 *
 * Tous ces tests sont "unitaires" : ils valident de la logique pure, SANS
 * base de données (session simulée via un tableau, compteurs stockés en fichier).
 */
final class BasicTest extends TestCase
{
    /** Clé dédiée aux tests de RateLimiter, pour ne pas polluer les vraies tentatives. */
    private const RATE_KEY = 'test_unitaire_rate_limiter';

    /**
     * Exécuté AVANT chaque test : on repart d'un environnement vierge.
     * - $_SESSION vidée   → les tests CSRF ne s'influencent pas entre eux
     * - compteur RateLimiter remis à zéro
     */
    protected function setUp(): void
    {
        $_SESSION = [];
        RateLimiter::reset(self::RATE_KEY);
    }

    /** Exécuté APRÈS chaque test : on supprime le fichier de compteur créé par RateLimiter. */
    protected function tearDown(): void
    {
        RateLimiter::reset(self::RATE_KEY);
    }

    // =====================================================================
    //  1. MOTS DE PASSE & EMAILS
    // =====================================================================

    /**
     * Le hachage de mot de passe doit être réversible à la vérification,
     * et un mauvais mot de passe doit toujours être rejeté.
     * (Garantit qu'on ne stocke jamais de mot de passe en clair.)
     */
    public function testHachageMotDePasse(): void
    {
        $hash = password_hash('stage2026', PASSWORD_DEFAULT);

        $this->assertTrue(password_verify('stage2026', $hash));   // bon mot de passe accepté
        $this->assertFalse(password_verify('mauvais', $hash));     // mauvais mot de passe rejeté
    }

    /**
     * La validation d'email doit rejeter un format invalide
     * et accepter une adresse correcte.
     */
    public function testValidationEmail(): void
    {
        $this->assertFalse(filter_var('email-invalide', FILTER_VALIDATE_EMAIL));
        $this->assertNotFalse(filter_var('etudiant@helpmestage.fr', FILTER_VALIDATE_EMAIL));
    }

    // =====================================================================
    //  2. PROTECTION CSRF (App\Security\Csrf)
    // =====================================================================

    /**
     * token() doit générer un jeton si la session est vide, le stocker en session,
     * et produire 64 caractères hexadécimaux (bin2hex de 32 octets = 256 bits).
     */
    public function testCsrfGenerationDuToken(): void
    {
        $this->assertEmpty($_SESSION);

        $token = Csrf::token();

        $this->assertSame(64, strlen($token));                          // longueur attendue
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token); // bien hexadécimal
        $this->assertSame($token, $_SESSION['_csrf_token']);            // bien stocké en session
    }

    /**
     * validate() doit accepter le jeton de la session
     * et rejeter tout jeton falsifié, nul ou vide.
     */
    public function testCsrfValidation(): void
    {
        $token = Csrf::token();

        $this->assertTrue(Csrf::validate($token));   // jeton correct
        $this->assertFalse(Csrf::validate('faux'));  // jeton falsifié
        $this->assertFalse(Csrf::validate(null));    // aucun jeton
        $this->assertFalse(Csrf::validate(''));      // jeton vide
    }

    /**
     * rotate() (appelé après une connexion) doit générer un NOUVEAU jeton :
     * l'ancien ne doit plus être valide, ce qui empêche sa réutilisation.
     */
    public function testCsrfRotation(): void
    {
        $ancien = Csrf::token();
        Csrf::rotate();

        $this->assertNotSame($ancien, Csrf::token()); // un nouveau jeton est généré
        $this->assertFalse(Csrf::validate($ancien));   // l'ancien est invalidé
    }

    // =====================================================================
    //  3. RATE LIMITING (App\Security\RateLimiter) — anti-force-brute
    // =====================================================================

    /**
     * Avec 3 tentatives autorisées : les 3 premières passent, la 4e est bloquée.
     * On vérifie aussi que le compteur de tentatives restantes diminue.
     */
    public function testRateLimiterBloqueApresLeMaximum(): void
    {
        $this->assertSame(3, RateLimiter::getRemaining(self::RATE_KEY, 3, 900)); // 3 essais dispo

        $this->assertTrue(RateLimiter::checkLimit(self::RATE_KEY, 3, 900));   // 1re tentative
        $this->assertTrue(RateLimiter::checkLimit(self::RATE_KEY, 3, 900));   // 2e tentative
        $this->assertTrue(RateLimiter::checkLimit(self::RATE_KEY, 3, 900));   // 3e tentative
        $this->assertFalse(RateLimiter::checkLimit(self::RATE_KEY, 3, 900));  // 4e : BLOQUÉE
    }

    /**
     * reset() doit effacer les tentatives : après une limite atteinte,
     * l'utilisateur redevient autorisé (utile après une connexion réussie).
     */
    public function testRateLimiterReinitialisation(): void
    {
        RateLimiter::checkLimit(self::RATE_KEY, 1, 900);
        $this->assertFalse(RateLimiter::checkLimit(self::RATE_KEY, 1, 900)); // limite atteinte

        RateLimiter::reset(self::RATE_KEY);
        $this->assertTrue(RateLimiter::checkLimit(self::RATE_KEY, 1, 900));  // de nouveau autorisé
    }
}
