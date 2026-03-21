<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use Twig\Environment;

class OfferController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(): string
    {
        $pdo = Database::getConnection();

        $search = trim((string) ($_GET['search_query'] ?? ''));
        $selectedSkill = trim((string) ($_GET['skills'] ?? ''));
        $selectedLocation = trim((string) ($_GET['location'] ?? ''));
        $selectedDuration = trim((string) ($_GET['duration'] ?? ''));
        $selectedSalary = trim((string) ($_GET['salary'] ?? ''));
        $selectedSort = trim((string) ($_GET['sort'] ?? 'recent'));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 6;
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(o.titre LIKE :search OR COALESCE(e.nom, o.entreprise) LIKE :search)";
            $params['search'] = '%' . $search . '%';
        }

        if ($selectedLocation !== '') {
            $where[] = "o.lieu LIKE :location";
            $params['location'] = '%' . $selectedLocation . '%';
        }

        if ($selectedDuration !== '') {
            $where[] = "o.duree_semaines <= :duration";
            $params['duration'] = (int) $selectedDuration;
        }

        if ($selectedSalary !== '') {
            $where[] = "o.remuneration >= :salary";
            $params['salary'] = (float) $selectedSalary;
        }

        if ($selectedSkill !== '') {
            $where[] = "EXISTS (
                SELECT 1
                FROM offre_competence oc
                INNER JOIN competences c ON c.id = oc.competence_id
                WHERE oc.offre_id = o.id
                  AND c.nom LIKE :skill
            )";
            $params['skill'] = '%' . $selectedSkill . '%';
        }

        $whereSql = '';
        if (!empty($where)) {
            $whereSql = 'WHERE ' . implode(' AND ', $where);
        }

        $orderBy = "o.created_at DESC";
        if ($selectedSort === 'salary_asc') {
            $orderBy = "o.remuneration ASC";
        } elseif ($selectedSort === 'salary_desc') {
            $orderBy = "o.remuneration DESC";
        } elseif ($selectedSort === 'duration_asc') {
            $orderBy = "o.duree_semaines ASC";
        } elseif ($selectedSort === 'duration_desc') {
            $orderBy = "o.duree_semaines DESC";
        }

        $countSql = "
            SELECT COUNT(*)
            FROM offres o
            LEFT JOIN entreprises e ON e.id = o.entreprise_id
            $whereSql
        ";

        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalOffers = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalOffers / $perPage));

        $sql = "
            SELECT
                o.id,
                o.titre,
                o.lieu,
                o.duree_semaines,
                o.remuneration,
                o.description,
                o.created_at,
                o.entreprise_id,
                COALESCE(e.nom, o.entreprise) AS entreprise_nom
            FROM offres o
            LEFT JOIN entreprises e ON e.id = o.entreprise_id
            $whereSql
            ORDER BY $orderBy
            LIMIT $perPage OFFSET $offset
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $offers = $stmt->fetchAll();

        $skillsStmt = $pdo->prepare("
            SELECT c.nom
            FROM offre_competence oc
            INNER JOIN competences c ON c.id = oc.competence_id
            WHERE oc.offre_id = :offre_id
            ORDER BY c.nom ASC
        ");

        foreach ($offers as &$offer) {
            $skillsStmt->execute(['offre_id' => $offer['id']]);
            $offer['skills'] = $skillsStmt->fetchAll();
        }
        unset($offer);

        return $this->twig->render('offers.html.twig', [
            'offers' => $offers,
            'search_query' => $search,
            'selected_skill' => $selectedSkill,
            'selected_location' => $selectedLocation,
            'selected_duration' => $selectedDuration,
            'selected_salary' => $selectedSalary,
            'selected_sort' => $selectedSort,
            'page' => $page,
            'total_pages' => $totalPages,
        ]);
    }
}