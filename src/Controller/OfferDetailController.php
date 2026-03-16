<?php

namespace App\Controller;

use App\Database;
use Twig\Environment;

class OfferDetailController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function show(int $id): string
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM offres WHERE id = :id");
        $stmt->execute(['id' => $id]);

        $offer = $stmt->fetch();

        if (!$offer) {
            http_response_code(404);
            return 'Offre introuvable';
        }

        return $this->twig->render('offer-detail.html.twig', [
            'site_name' => 'Help Me Stage',
            'offer' => $offer,
        ]);
    }
}