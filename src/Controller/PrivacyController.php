<?php

namespace App\Controller;

use Twig\Environment;

/**
 * Contrôleur de la politique de confidentialité
 *
 * Page statique publique obligatoire (RGPD).
 * Détaille la collecte, le traitement et la conservation des données personnelles.
 */
class PrivacyController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    /** Affiche la politique de confidentialité (RGPD) */
    public function index(): string
    {
        return $this->twig->render('privacy.html.twig', [
            'site_name' => 'Help Me Stage',
        ]);
    }
}