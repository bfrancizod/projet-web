<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires des principales fonctions de sécurité.
 */
final class BasicTest extends TestCase
{
    private const RATE_KEY = 'test_unitaire_rate_limiter';

    protected function setUp(): void
    {
        $_SESSION = [];
        RateLimiter::reset(self::RATE_KEY);
    }

    protected function tearDown(): void
    {
        RateLimiter::reset(self::RATE_KEY);
    }

    // MOTS DE PASSE & EMAILS

    public function testHachageMotDePasse(): void
    {
        $hash = password_hash('stage2026', PASSWORD_DEFAULT);

        $this->assertTrue(password_verify('stage2026', $hash));
        $this->assertFalse(password_verify('mauvais', $hash));
    }

    public function testValidationEmail(): void
    {
        $this->assertFalse(filter_var('email-invalide', FILTER_VALIDATE_EMAIL));
        $this->assertNotFalse(filter_var('etudiant@helpmestage.fr', FILTER_VALIDATE_EMAIL));
    }

    // CSRF

    public function testCsrfGenerationDuToken(): void
    {
        $token = Csrf::token();

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, $_SESSION['_csrf_token']);
    }

    public function testCsrfValidation(): void
    {
        $token = Csrf::token();

        $this->assertTrue(Csrf::validate($token));
        $this->assertFalse(Csrf::validate('faux'));
        $this->assertFalse(Csrf::validate(null));
        $this->assertFalse(Csrf::validate(''));
    }

    public function testCsrfRotation(): void
    {
        $ancien = Csrf::token();

        Csrf::rotate();

        $this->assertNotSame($ancien, Csrf::token());
        $this->assertFalse(Csrf::validate($ancien));
    }

    // RATE LIMITER

    public function testRateLimiterBloqueApresLeMaximum(): void
    {
        $this->assertSame(3, RateLimiter::getRemaining(self::RATE_KEY, 3, 900));

        $this->assertTrue(RateLimiter::checkLimit(self::RATE_KEY, 3, 900));
        $this->assertTrue(RateLimiter::checkLimit(self::RATE_KEY, 3, 900));
        $this->assertTrue(RateLimiter::checkLimit(self::RATE_KEY, 3, 900));
        $this->assertFalse(RateLimiter::checkLimit(self::RATE_KEY, 3, 900));
    }

    public function testRateLimiterReinitialisation(): void
    {
        RateLimiter::checkLimit(self::RATE_KEY, 1, 900);

        $this->assertFalse(RateLimiter::checkLimit(self::RATE_KEY, 1, 900));

        RateLimiter::reset(self::RATE_KEY);

        $this->assertTrue(RateLimiter::checkLimit(self::RATE_KEY, 1, 900));
    }
}