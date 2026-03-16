<?php

namespace App\Controller;

use Twig\Environment;

class HomeController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(): string
    {
        return $this->twig->render('home.html.twig', [
            'site_name' => 'Help Me Stage',
            'hero_title' => 'Trouve ton stage de rêve',
            'hero_text' => 'Nous connectons les étudiants avec les meilleures opportunités de stage en France. Simplifie ta recherche et décroche le stage parfait.',
            'steps' => [
                [
                    'icon' => '🔍',
                    'title' => 'Recherche',
                    'text' => 'Parcours des centaines d’offres de stage adaptées à ton profil et tes ambitions.',
                ],
                [
                    'icon' => '📝',
                    'title' => 'Postule',
                    'text' => 'Envoie ta candidature en quelques clics avec un profil optimisé.',
                ],
                [
                    'icon' => '💼',
                    'title' => 'Décroche',
                    'text' => 'Obtiens le stage qui correspond à tes aspirations professionnelles.',
                ],
            ]
        ]);
    }
}