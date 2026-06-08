<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de l'application Help Me Stage.
 *
 * Regroupe dans un seul fichier :
 * - des tests de base (PHPUnit, hachage de mot de passe, validation d'email)
 * - les tests de la protection CSRF (App\Security\Csrf)
 * - les tests de la limitation anti-force-brute (App\Security\RateLimiter)
 *
 * Aucun de ces tests n'a besoin de base de données : ils valident de la
 * logique pure (sessions simulées, fichiers de compteur).
 */
final class BasicTest extends TestCase
{
    /** Clé unique pour les tests de RateLimiter (n'interfère pas avec les vraies tentatives). */
    private const RATE_KEY = 'test_unitaire_rate_limiter';

    /** Avant chaque test : session vidée + compteur de tentatives remis à zéro. */
    protected function setUp(): void
    {
        $_SESSION = [];
        RateLimiter::reset(self::RATE_KEY);
    }

    /** Après chaque test : on nettoie le fichier de compteur créé par RateLimiter. */
    protected function tearDown(): void
    {
        RateLimiter::reset(self::RATE_KEY);
    }

    // =====================================================================
    //  1. Tests de base
    // =====================================================================

    /** Test minimal : vérifie que PHPUnit est correctement configuré. */
    public function testTrueIsTrue(): void
    {
        $this->assertTrue(true);
    }

    /** Un mot de passe haché avec password_hash() doit être validé par password_verify(). */
    public function testPasswordHashIsValid(): void
    {
        $password = 'stage2026';

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $this->assertTrue(password_verify($password, $hash));
        // Un mauvais mot de passe ne doit jamais être validé
        $this->assertFalse(password_verify('mauvais', $hash));
    }

    /** filter_var doit rejeter un email mal formé et accepter un email valide. */
    public function testEmailValidation(): void
    {
        $this->assertFalse(filter_var('email-invalide', FILTER_VALIDATE_EMAIL));
        $this->assertNotFalse(filter_var('etudiant@helpmestage.fr', FILTER_VALIDATE_EMAIL));
    }

    // =====================================================================
    //  2. Protection CSRF (App\Security\Csrf)
    // =====================================================================

    /** Le token CSRF doit faire 64 caractères hexadécimaux (bin2hex de 32 octets). */
    public function testCsrfTokenEstUneChaineHexDe64Caracteres(): void
    {
        $token = Csrf::token();

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    /** Appeler token() plusieurs fois dans la même session renvoie la même valeur. */
    public function testCsrfTokenEstStableDansLaSession(): void
    {
        $this->assertSame(Csrf::token(), Csrf::token());
    }

    /** validate() accepte le bon token et rejette tout le reste. */
    public function testCsrfValidateAccepteLeBonTokenEtRejetteLesAutres(): void
    {
        $token = Csrf::token();

        $this->assertTrue(Csrf::validate($token));   // bon token
        $this->assertFalse(Csrf::validate('faux'));  // mauvais token
        $this->assertFalse(Csrf::validate(null));    // pas de token
        $this->assertFalse(Csrf::validate(''));      // token vide
    }

    /** rotate() (appelé à la connexion) invalide l'ancien token. */
    public function testCsrfRotateInvalideLAncienToken(): void
    {
        $ancien = Csrf::token();
        Csrf::rotate();

        $this->assertFalse(Csrf::validate($ancien));
    }

    // =====================================================================
    //  3. Limitation anti-force-brute (App\Security\RateLimiter)
    // =====================================================================

    /** Avec 3 essais autorisés : les 3 premiers passent, le 4e est bloqué. */
    public function testRateLimiterAutoriseJusquAuMaxPuisBloque(): void
    {
        $this->assertTrue(RateLimiter::checkLimit(self::RATE_KEY, 3, 900));
        $this->assertTrue(RateLimiter::checkLimit(self::RATE_KEY, 3, 900));
        $this->assertTrue(RateLimiter::checkLimit(self::RATE_KEY, 3, 900));
        $this->assertFalse(RateLimiter::checkLimit(self::RATE_KEY, 3, 900)); // bloqué
    }

    /** Le nombre de tentatives restantes part du maximum et diminue à chaque essai. */
    public function testRateLimiterTentativesRestantesDiminuent(): void
    {
        $this->assertSame(5, RateLimiter::getRemaining(self::RATE_KEY, 5, 900));

        RateLimiter::checkLimit(self::RATE_KEY, 5, 900);
        $this->assertSame(4, RateLimiter::getRemaining(self::RATE_KEY, 5, 900));
    }

    /** reset() efface les tentatives : l'utilisateur est de nouveau autorisé. */
    public function testRateLimiterResetReautoriseLesTentatives(): void
    {
        RateLimiter::checkLimit(self::RATE_KEY, 1, 900);
        $this->assertFalse(RateLimiter::checkLimit(self::RATE_KEY, 1, 900));

        RateLimiter::reset(self::RATE_KEY);
        $this->assertTrue(RateLimiter::checkLimit(self::RATE_KEY, 1, 900));
    }
}
