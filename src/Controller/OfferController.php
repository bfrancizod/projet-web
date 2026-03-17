<?php

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

        $selectedSkill = trim($_GET['skills'] ?? '');
        $selectedLocation = trim($_GET['location'] ?? '');
        $selectedDuration = trim($_GET['duration'] ?? '');
        $selectedSalary = trim($_GET['salary'] ?? '');
        $selectedSort = trim($_GET['sort'] ?? 'recent');
        $search = trim($_GET['q'] ?? '');

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 6;
        $offset = ($page - 1) * $perPage;

        $sql = "
            FROM offres o
            LEFT JOIN offre_competence oc ON oc.offre_id = o.id
            LEFT JOIN competences c ON c.id = oc.competence_id
            WHERE 1=1
        ";

        $params = [];

        if ($selectedSkill !== '') {
            $sql .= " AND c.nom LIKE :skill ";
            $params['skill'] = '%' . $selectedSkill . '%';
        }

        if ($selectedLocation !== '') {
            $sql .= " AND o.lieu LIKE :location ";
            $params['location'] = '%' . $selectedLocation . '%';
        }

        if ($selectedDuration !== '' && is_numeric($selectedDuration)) {
            $sql .= " AND o.duree_semaines <= :duration ";
            $params['duration'] = (int) $selectedDuration;
        }

        if ($selectedSalary !== '' && is_numeric($selectedSalary)) {
            $sql .= " AND o.remuneration >= :salary ";
            $params['salary'] = (float) $selectedSalary;
        }

        if ($search !== '') {
            $sql .= " AND (o.titre LIKE :search OR o.entreprise LIKE :search OR o.description LIKE :search) ";
            $params['search'] = '%' . $search . '%';
        }

        $countStmt = $pdo->prepare("SELECT COUNT(DISTINCT o.id) " . $sql);
        $countStmt->execute($params);
        $totalOffers = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalOffers / $perPage));

        $orderBy = " ORDER BY o.created_at DESC ";
        if ($selectedSort === 'salary_desc') {
            $orderBy = " ORDER BY o.remuneration DESC ";
        } elseif ($selectedSort === 'salary_asc') {
            $orderBy = " ORDER BY o.remuneration ASC ";
        } elseif ($selectedSort === 'duration_asc') {
            $orderBy = " ORDER BY o.duree_semaines ASC ";
        }

        $stmt = $pdo->prepare("
            SELECT DISTINCT o.*
            " . $sql . $orderBy . "
            LIMIT :limit OFFSET :offset
        ");

        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);

        $stmt->execute();
        $offers = $stmt->fetchAll();

        foreach ($offers as &$offer) {
            $stmtSkills = $pdo->prepare("
                SELECT c.nom
                FROM competences c
                INNER JOIN offre_competence oc ON oc.competence_id = c.id
                WHERE oc.offre_id = :id
                ORDER BY c.nom ASC
            ");
            $stmtSkills->execute(['id' => $offer['id']]);
            $offer['skills'] = $stmtSkills->fetchAll();
        }

        return $this->twig->render('offers.html.twig', [
            'site_name' => 'Help Me Stage',
            'offers' => $offers,
            'selected_skill' => $selectedSkill,
            'selected_location' => $selectedLocation,
            'selected_duration' => $selectedDuration,
            'selected_salary' => $selectedSalary,
            'selected_sort' => $selectedSort,
            'search_query' => $search,
            'page' => $page,
            'total_pages' => $totalPages
        ]);
    }
}