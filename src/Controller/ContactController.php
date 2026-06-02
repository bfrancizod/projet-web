<?php

namespace App\Controller;

use Twig\Environment;

/**
 * Contrôleur de la page de contact
 *
 * Page statique publique, accessible sans connexion.
 * Aucun traitement de formulaire — la page affiche uniquement les coordonnées.
 */
class ContactController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    // Affiche la page de contact statique 
    public function index(): string
    {
        return $this->twig->render('contact.html.twig', [
            'site_name' => 'Help Me Stage'
        ]);
    }
}