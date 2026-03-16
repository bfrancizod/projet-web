<?php

namespace App\Controller;

use Twig\Environment;

class PrivacyController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(): string
    {
        return $this->twig->render('privacy.html.twig', [
            'site_name' => 'Help Me Stage'
        ]);
    }
}