<?php

declare(strict_types=1);

use App\Controller\LegalController;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Tests unitaires des principales fonctions de sécurité
 * et d'un contrôleur de l'application.
 */
final class BasicTest extends TestCase
{
    // ===================== MOTS DE PASSE & EMAILS =====================

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

    // ===================== CONTRÔLEUR =====================

    /**
     * Teste le LegalController de bout en bout : on l'instancie avec un vrai
     * moteur Twig (configuré comme dans l'application), on appelle son action
     * index(), et on vérifie qu'il renvoie bien une page HTML contenant les
     * mentions légales attendues.
     *
     * C'est un test de contrôleur : il valide que la couche Controller produit
     * la bonne sortie (rendu de la vue) sans avoir besoin de base de données.
     */
    public function testLegalControllerAfficheLesMentionsLegales(): void
    {
        // Environnement Twig identique à celui d'index.php (templates + autoescape)
        $loader = new FilesystemLoader(__DIR__ . '/../templates');
        $twig = new Environment($loader, [
            'cache' => false,
            'autoescape' => 'html',
        ]);

        // On instancie le contrôleur et on appelle son action
        $controller = new LegalController($twig);
        $html = $controller->index();

        // La sortie doit être une vraie page HTML…
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        // …affichant le titre des mentions légales…
        $this->assertStringContainsString('Mentions légales', $html);
        // …et contenant des informations légales obligatoires (ex. le SIRET).
        $this->assertStringContainsString('SIRET', $html);
    }
}
