<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test de smoke de base — vérifie uniquement que PHPUnit est fonctionnel.
 *
 * Ce test trivial permet de valider que la configuration PHPUnit est correcte
 * avant d'ajouter de vrais tests (controllers, repositories, sécurité).
 * À enrichir avec des tests unitaires réels (AuthController, Csrf, ApplyController…).
 */
final class BasicTest extends TestCase
{
    /** Vérifie que PHPUnit s'exécute correctement (test minimal) */
    public function testTrueIsTrue(): void
    {
        $this->assertTrue(true);
    }
}