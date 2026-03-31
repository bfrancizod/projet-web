<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Support\PilotPromotionAccess;
use PDO;
use Twig\Environment;

class PilotStudentsController
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
        $currentUserRole = $_SESSION['user']['role'] ?? null;
        $currentUserId = (int) ($_SESSION['user']['id'] ?? 0);

        $allowedPromotionIds = [];
        if ($currentUserRole === 'pilote') {
            $allowedPromotionIds = PilotPromotionAccess::getAssignedPromotionIds($pdo, $currentUserId);
        }

        $selectedPromotionId = isset($_GET['promotion_id']) && ctype_digit((string) $_GET['promotion_id'])
            ? (int) $_GET['promotion_id']
            : null;

        if (
            $currentUserRole === 'pilote'
            && $selectedPromotionId !== null
            && !in_array($selectedPromotionId, $allowedPromotionIds, true)
        ) {
            $selectedPromotionId = null;
        }

        $search = trim((string) ($_GET['q'] ?? ''));
        $currentPage = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0
            ? (int) $_GET['page']
            : 1;

        $perPage = 10;

        if ($currentUserRole === 'pilote') {
            $promotions = PilotPromotionAccess::getPromotionsByIds($pdo, $allowedPromotionIds);
        } else {
            $promotionsStmt = $pdo->query("
                SELECT id, label, academic_year
                FROM promotions
                ORDER BY academic_year DESC, label ASC
            ");
            $promotions = $promotionsStmt->fetchAll();
        }

        $countSql = "
            SELECT COUNT(*)
            FROM users u
            INNER JOIN student_profiles sp ON sp.user_id = u.id
            WHERE u.role = 'etudiant'
        ";

        $countParams = [];

        if ($currentUserRole === 'pilote') {
            if ($allowedPromotionIds === []) {
                $countSql .= " AND 1 = 0 ";
            } else {
                $placeholders = [];
                foreach ($allowedPromotionIds as $index => $promotionId) {
                    $key = ':allowed_promotion_' . $index;
                    $placeholders[] = $key;
                    $countParams['allowed_promotion_' . $index] = $promotionId;
                }
                $countSql .= " AND sp.promotion_id IN (" . implode(', ', $placeholders) . ")";
            }
        }

        if ($selectedPromotionId !== null) {
            $countSql .= " AND sp.promotion_id = :promotion_id";
            $countParams['promotion_id'] = $selectedPromotionId;
        }

        if ($search !== '') {
            $countSql .= "
                AND (
                    u.nom LIKE :search_nom
                    OR u.prenom LIKE :search_prenom
                    OR u.email LIKE :search_email
                )
            ";
            $searchValue = '%' . $search . '%';
            $countParams['search_nom'] = $searchValue;
            $countParams['search_prenom'] = $searchValue;
            $countParams['search_email'] = $searchValue;
        }

        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $totalStudents = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($totalStudents / $perPage));
        if ($currentPage > $totalPages) {
            $currentPage = $totalPages;
        }

        $offset = ($currentPage - 1) * $perPage;

        $sql = "
            SELECT
                u.id,
                u.nom,
                u.prenom,
                u.email,
                sp.formation,
                sp.status,
                sp.last_activity,
                p.label AS promotion_label,
                p.academic_year
            FROM users u
            INNER JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN promotions p ON p.id = sp.promotion_id
            WHERE u.role = 'etudiant'
        ";

        $params = [];

        if ($currentUserRole === 'pilote') {
            if ($allowedPromotionIds === []) {
                $sql .= " AND 1 = 0 ";
            } else {
                $placeholders = [];
                foreach ($allowedPromotionIds as $index => $promotionId) {
                    $key = ':allowed_promotion_' . $index;
                    $placeholders[] = $key;
                    $params['allowed_promotion_' . $index] = $promotionId;
                }
                $sql .= " AND sp.promotion_id IN (" . implode(', ', $placeholders) . ")";
            }
        }

        if ($selectedPromotionId !== null) {
            $sql .= " AND sp.promotion_id = :promotion_id";
            $params['promotion_id'] = $selectedPromotionId;
        }

        if ($search !== '') {
            $sql .= "
                AND (
                    u.nom LIKE :search_nom
                    OR u.prenom LIKE :search_prenom
                    OR u.email LIKE :search_email
                )
            ";
            $searchValue = '%' . $search . '%';
            $params['search_nom'] = $searchValue;
            $params['search_prenom'] = $searchValue;
            $params['search_email'] = $searchValue;
        }

        $sql .= " ORDER BY p.academic_year DESC, p.label ASC, u.nom ASC, u.prenom ASC LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);

        foreach ($params as $name => $value) {
            if (in_array($name, ['search_nom', 'search_prenom', 'search_email'], true)) {
                $stmt->bindValue(':' . $name, $value, PDO::PARAM_STR);
            } else {
                $stmt->bindValue(':' . $name, (int) $value, PDO::PARAM_INT);
            }
        }

        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $students = $stmt->fetchAll();

        return $this->twig->render('pilot-students.html.twig', [
            'students' => $students,
            'promotions' => $promotions,
            'selected_promotion_id' => $selectedPromotionId,
            'search' => $search,
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
        ]);
    }
}