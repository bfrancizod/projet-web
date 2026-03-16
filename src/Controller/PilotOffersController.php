<?php

namespace App\Controller;

use App\Database;
use Twig\Environment;

class PilotOffersController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(): string
    {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pilote') {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();

        $stmt = $pdo->query("
            SELECT *
            FROM offres
            ORDER BY created_at DESC
        ");

        $offers = $stmt->fetchAll();

        return $this->twig->render('pilot-offers.html.twig', [
            'site_name' => 'Help Me Stage',
            'user' => $_SESSION['user'],
            'offers' => $offers
        ]);
    }
}