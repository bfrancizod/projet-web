<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de base.
 *
 * Vérifie :
 * - que PHPUnit fonctionne correctement
 * - que le hashage des mots de passe est valide
 * - que la validation d'email rejette les formats incorrects
 */
final class BasicTest extends TestCase
{
    /**
     * Test minimal de fonctionnement de PHPUnit.
     */
    public function testTrueIsTrue(): void
    {
        $this->assertTrue(true);
    }

    /**
     * Vérifie qu'un mot de passe hashé peut être validé.
     */
    public function testPasswordHashIsValid(): void
    {
        $password = 'stage2026';

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $this->assertTrue(password_verify($password, $hash));
    }

    /**
     * Vérifie qu'un email invalide est rejeté.
     */
    public function testInvalidEmailIsRejected(): void
    {
        $email = 'email-invalide';

        $this->assertFalse(filter_var($email, FILTER_VALIDATE_EMAIL));
    }
}