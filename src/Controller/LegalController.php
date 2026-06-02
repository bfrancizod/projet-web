<?php

namespace App\Controller;

use Twig\Environment;

/**
 * Contrôleur des mentions légales
 *
 * Page statique publique obligatoire (loi pour la confiance en l'économie numérique).
 * Contient les informations sur l'éditeur, l'hébergeur et les droits des utilisateurs.
 */
class LegalController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    /** Affiche la page des mentions légales */
    public function index(): string
    {
        return $this->twig->render('legal.html.twig', [
            'site_name' => 'Help Me Stage',
        ]);
    }
}