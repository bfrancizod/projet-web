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

        $search = trim((string) ($_GET['q'] ?? ''));

        $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
        $page = ($page !== false && $page !== null && $page > 0) ? $page : 1;

        $countSql = "
            SELECT COUNT(*)
            FROM offres o
            LEFT JOIN entreprises e ON e.id = o.entreprise_id
            WHERE 1 = 1
        ";

        $dataSql = "
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
            WHERE 1 = 1
        ";

        $params = [];

        if ($search !== '') {
            $countSql .= "
                AND (
                    o.titre LIKE :search
                    OR o.lieu LIKE :search
                    OR o.entreprise LIKE :search
                    OR e.nom LIKE :search
                )
            ";

            $dataSql .= "
                AND (
                    o.titre LIKE :search
                    OR o.lieu LIKE :search
                    OR o.entreprise LIKE :search
                    OR e.nom LIKE :search
                )
            ";

            $params['search'] = '%' . $search . '%';
        }

        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalOffers = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($totalOffers / self::PER_PAGE));

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * self::PER_PAGE;

        $dataSql .= "
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $pdo->prepare($dataSql);

        if ($search !== '') {
            $stmt->bindValue(':search', '%' . $search . '%', \PDO::PARAM_STR);
        }

        $stmt->bindValue(':limit', self::PER_PAGE, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $offers = $stmt->fetchAll();

        // Suggestions autocomplete : titres + entreprises + lieux
        $suggestionsStmt = $pdo->query("
            SELECT suggestion
            FROM (
                SELECT TRIM(o.titre) AS suggestion
                FROM offres o
                WHERE o.titre IS NOT NULL AND TRIM(o.titre) <> ''

                UNION

                SELECT TRIM(COALESCE(e.nom, o.entreprise)) AS suggestion
                FROM offres o
                LEFT JOIN entreprises e ON e.id = o.entreprise_id
                WHERE COALESCE(e.nom, o.entreprise) IS NOT NULL
                  AND TRIM(COALESCE(e.nom, o.entreprise)) <> ''

                UNION

                SELECT TRIM(o.lieu) AS suggestion
                FROM offres o
                WHERE o.lieu IS NOT NULL AND TRIM(o.lieu) <> ''
            ) AS suggestions
            ORDER BY suggestion ASC
        ");

        $offerSuggestions = $suggestionsStmt->fetchAll(\PDO::FETCH_COLUMN);

        return $this->twig->render('pilot-offers.html.twig', [
            'offers' => $offers,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalOffers' => $totalOffers,
            'search' => $search,
            'offerTitles' => $offerSuggestions,
        ]);
    }
}