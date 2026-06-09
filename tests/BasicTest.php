<?php

declare(strict_types=1);


use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires des principales fonctions de sécurité.
 */
final class BasicTest extends TestCase
{


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

}