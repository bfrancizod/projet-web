<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use Twig\Environment;

class PilotOffersController
{
    private Environment $twig;
    private const PER_PAGE = 10;

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

        $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
        $page = ($page !== false && $page !== null && $page > 0) ? $page : 1;

        $countStmt = $pdo->query("
            SELECT COUNT(*) 
            FROM offres
        ");
        $totalOffers = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($totalOffers / self::PER_PAGE));

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * self::PER_PAGE;

        $stmt = $pdo->prepare("
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
            LIMIT :limit OFFSET :offset
        ");

        $stmt->bindValue(':limit', self::PER_PAGE, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $offers = $stmt->fetchAll();

        return $this->twig->render('pilot-offers.html.twig', [
            'offers' => $offers,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalOffers' => $totalOffers,
        ]);
    }
}