<?php

declare(strict_types=1);

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
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();

        $stmt = $pdo->query("
            SELECT
                o.id,
                o.titre,
                o.lieu,
                o.remuneration,
                o.duree_semaines,
                o.created_at,
                COALESCE(e.nom, o.entreprise) AS entreprise_nom
            FROM offres o
            LEFT JOIN entreprises e ON e.id = o.entreprise_id
            ORDER BY o.created_at DESC, o.id DESC
        ");

        $offers = $stmt->fetchAll();

        return $this->twig->render('pilot-offers.html.twig', [
            'offers' => $offers,
        ]);
    }
}