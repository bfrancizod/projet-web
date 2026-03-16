<?php

namespace App\Controller;

use Twig\Environment;

class ContactController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(): string
    {
        return $this->twig->render('contact.html.twig', [
            'site_name' => 'Help Me Stage'
        ]);
    }
}