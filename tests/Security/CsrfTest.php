<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use App\Security\Csrf;

/**
 * Tests Unitaires pour la protection CSRF.
 */
final class CsrfTest extends TestCase
{
    /**
     * Réinitialise l'environnement de session avant chaque test.
     */
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    /**
     * Vérifie la génération automatique d'un token si la session est vierge.
     */
    public function testTokenIsGeneratedIfEmpty(): void
    {
        $this->assertEmpty($_SESSION);

        $token = Csrf::token();

        $this->assertNotEmpty($token);
        $this->assertSame($token, $_SESSION['_csrf_token']);
    }

    /**
     * S'assure que le validateur accepte le token actuellement en session.
     */
    public function testValidTokenIsAccepted(): void
    {
        $validToken = Csrf::token();

        $this->assertTrue(Csrf::validate($validToken));
    }

    /**
     * S'assure que le validateur bloque toute tentative avec un token falsifié.
     */
    public function testInvalidTokenIsRejected(): void
    {
        Csrf::token();

        $fakeToken = "invalid_token_string";

        $this->assertFalse(Csrf::validate($fakeToken));
        $this->assertFalse(Csrf::validate(null));
    }

    /**
     * Vérifie que la rotation du token génère bien une nouvelle valeur unique.
     */
    public function testTokenRotationGeneratesNewToken(): void
    {
        $firstToken = Csrf::token();

        Csrf::rotate();
        
        $secondToken = Csrf::token();

        $this->assertNotSame($firstToken, $secondToken);
    }
}
