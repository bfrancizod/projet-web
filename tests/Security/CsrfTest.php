<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Security\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    /**
     * Vérifie que le token généré fait bien 64 caractères hexadécimaux
     * et qu'il est correctement stocké en session.
     */
    public function testCsrfGenerationDuToken(): void
    {
        $token = Csrf::token();

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, $_SESSION['_csrf_token']);
    }

    /**
     * Teste la validation du token.
     * Vérifie qu'un bon token est accepté, et qu'un faux, nul ou vide est refusé.
     */
    public function testCsrfValidation(): void
    {
        $token = Csrf::token();

        $this->assertTrue(Csrf::validate($token));
        $this->assertFalse(Csrf::validate('faux'));
        $this->assertFalse(Csrf::validate(null));
        $this->assertFalse(Csrf::validate(''));
    }

    /**
     * Vérifie que la rotation du token fonctionne bien.
     * Le nouveau token doit être différent de l'ancien, et l'ancien ne doit plus être valide.
     */
    public function testCsrfRotation(): void
    {
        $ancien = Csrf::token();

        Csrf::rotate();

        $this->assertNotSame($ancien, Csrf::token());
        $this->assertFalse(Csrf::validate($ancien));
    }
}
