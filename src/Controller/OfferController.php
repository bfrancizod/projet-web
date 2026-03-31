<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use Twig\Environment;
use PDO;

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

        $searchQuery = trim((string) ($_GET['search_query'] ?? ''));
        $skillsQuery = trim((string) ($_GET['skills'] ?? ''));
        $locationQuery = trim((string) ($_GET['location'] ?? ''));
        $durationQuery = trim((string) ($_GET['duration'] ?? ''));
        $salaryQuery = trim((string) ($_GET['salary'] ?? ''));
        $sortQuery = trim((string) ($_GET['sort'] ?? 'recent'));

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 6;
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($searchQuery !== '') {
            $where[] = '(
                o.titre LIKE :search_title
                OR o.entreprise LIKE :search_company
                OR o.description LIKE :search_description
            )';

            $searchValue = '%' . $searchQuery . '%';
            $params['search_title'] = $searchValue;
            $params['search_company'] = $searchValue;
            $params['search_description'] = $searchValue;
        }

        if ($locationQuery !== '') {
            $where[] = 'o.lieu LIKE :location';
            $params['location'] = '%' . $locationQuery . '%';
        }

        if ($durationQuery !== '' && ctype_digit($durationQuery)) {
            $where[] = 'o.duree_semaines <= :duration';
            $params['duration'] = (int) $durationQuery;
        }

        if ($salaryQuery !== '' && is_numeric($salaryQuery)) {
            $where[] = 'o.remuneration >= :salary';
            $params['salary'] = (float) $salaryQuery;
        }

        if ($skillsQuery !== '') {
            $skillWords = array_filter(array_map('trim', explode(',', $skillsQuery)));

            if ($skillWords !== []) {
                $skillConditions = [];

                foreach ($skillWords as $index => $skillWord) {
                    $paramName = 'skill_' . $index;
                    $skillConditions[] = "EXISTS (
                        SELECT 1
                        FROM offre_competence oc_filter
                        INNER JOIN competences c_filter ON c_filter.id = oc_filter.competence_id
                        WHERE oc_filter.offre_id = o.id
                          AND c_filter.nom LIKE :$paramName
                    )";
                    $params[$paramName] = '%' . $skillWord . '%';
                }

                $where[] = '(' . implode(' AND ', $skillConditions) . ')';
            }
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $orderBy = 'o.created_at DESC';
        if ($sortQuery === 'salary_asc') {
            $orderBy = 'o.remuneration ASC';
        } elseif ($sortQuery === 'salary_desc') {
            $orderBy = 'o.remuneration DESC';
        } elseif ($sortQuery === 'duration_asc') {
            $orderBy = 'o.duree_semaines ASC';
        } elseif ($sortQuery === 'duration_desc') {
            $orderBy = 'o.duree_semaines DESC';
        }

        $countSql = "
            SELECT COUNT(DISTINCT o.id)
            FROM offres o
            $whereSql
        ";

        $countStmt = $pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $countStmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            } else {
                $countStmt->bindValue(':' . $key, $value);
            }
        }
        $countStmt->execute();

        $totalOffers = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalOffers / $perPage));

        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $sql = "
            SELECT
                o.id,
                o.titre,
                o.entreprise,
                o.lieu,
                o.duree_semaines,
                o.remuneration,
                o.description,
                o.created_at,
                COALESCE(o.entreprise, 'Entreprise non définie') AS entreprise_nom,
                GROUP_CONCAT(DISTINCT c.nom ORDER BY c.nom SEPARATOR '||') AS skills_concat
            FROM offres o
            LEFT JOIN offre_competence oc ON oc.offre_id = o.id
            LEFT JOIN competences c ON c.id = oc.competence_id
            $whereSql
            GROUP BY
                o.id,
                o.titre,
                o.entreprise,
                o.lieu,
                o.duree_semaines,
                o.remuneration,
                o.description,
                o.created_at
            ORDER BY $orderBy
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $pdo->prepare($sql);

        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':' . $key, $value);
            }
        }

        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($offers as &$offer) {
            $offer['skills'] = [];

            if (!empty($offer['skills_concat'])) {
                $skillNames = explode('||', (string) $offer['skills_concat']);
                foreach ($skillNames as $skillName) {
                    $skillName = trim($skillName);
                    if ($skillName !== '') {
                        $offer['skills'][] = ['nom' => $skillName];
                    }
                }
            }
        }
        unset($offer);

        return $this->twig->render('offers.html.twig', [
            'offers' => $offers,
            'search_query' => $searchQuery,
            'skills_query' => $skillsQuery,
            'location_query' => $locationQuery,
            'duration_query' => $durationQuery,
            'salary_query' => $salaryQuery,
            'sort_query' => $sortQuery,
            'current_page' => $page,
            'total_pages' => $totalPages,
        ]);
    }

    public function show(int $id): string
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT
                o.id,
                o.titre,
                o.entreprise,
                o.lieu,
                o.duree_semaines,
                o.remuneration,
                o.description,
                o.created_at,
                o.entreprise_id,
                e.nom AS entreprise_nom,
                e.siret AS entreprise_siret,
                e.secteur AS entreprise_secteur,
                e.ville AS entreprise_ville,
                e.site_web AS entreprise_site_web,
                e.note AS entreprise_note,
                e.commentaire AS entreprise_commentaire
            FROM offres o
            LEFT JOIN entreprises e ON e.id = o.entreprise_id
            WHERE o.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $offer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$offer) {
            http_response_code(404);
            return 'Offre introuvable.';
        }

        $skillsStmt = $pdo->prepare("
            SELECT c.nom
            FROM offre_competence oc
            INNER JOIN competences c ON c.id = oc.competence_id
            WHERE oc.offre_id = :offre_id
            ORDER BY c.nom ASC
        ");
        $skillsStmt->execute(['offre_id' => $id]);
        $skills = $skillsStmt->fetchAll(PDO::FETCH_ASSOC);

        $isInWishlist = false;
        $hasApplied = false;

        if (isset($_SESSION['user']) && ($_SESSION['user']['role'] ?? null) === 'etudiant') {
            $userId = (int) $_SESSION['user']['id'];

            $wishlistStmt = $pdo->prepare("
                SELECT 1
                FROM student_wishlist
                WHERE user_id = :user_id
                  AND offre_id = :offre_id
                LIMIT 1
            ");
            $wishlistStmt->execute([
                'user_id' => $userId,
                'offre_id' => $id,
            ]);

            $isInWishlist = (bool) $wishlistStmt->fetchColumn();

            $applicationStmt = $pdo->prepare("
                SELECT 1
                FROM candidatures
                WHERE student_user_id = :user_id
                  AND offre_id = :offre_id
                LIMIT 1
            ");
            $applicationStmt->execute([
                'user_id' => $userId,
                'offre_id' => $id,
            ]);

            $hasApplied = (bool) $applicationStmt->fetchColumn();
        }

        return $this->twig->render('offer-detail.html.twig', [
            'offer' => $offer,
            'skills' => $skills,
            'is_in_wishlist' => $isInWishlist,
            'has_applied' => $hasApplied,
        ]);
    }
}