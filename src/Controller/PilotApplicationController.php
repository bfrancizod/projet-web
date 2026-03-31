<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;
use Twig\Environment;
use PDO;

class PilotApplicationController
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

        $selectedPromotionId = isset($_GET['promotion_id']) && ctype_digit((string) $_GET['promotion_id'])
            ? (int) $_GET['promotion_id']
            : null;

        $search = trim((string) ($_GET['q'] ?? ''));

        $currentPage = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0
            ? (int) $_GET['page']
            : 1;

        $promotionsStmt = $pdo->query("
            SELECT id, label
            FROM promotions
            WHERE is_active = 1
            ORDER BY label ASC
        ");
        $promotions = $promotionsStmt->fetchAll();

        $countSql = "
            SELECT COUNT(*)
            FROM candidatures c
            INNER JOIN users u ON u.id = c.student_user_id
            INNER JOIN offres o ON o.id = c.offre_id
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN promotions p ON p.id = sp.promotion_id
            WHERE 1 = 1
        ";

        $countParams = [];

        if ($selectedPromotionId !== null) {
            $countSql .= " AND sp.promotion_id = :promotion_id ";
            $countParams['promotion_id'] = $selectedPromotionId;
        }

        if ($search !== '') {
            $countSql .= "
                AND (
                    u.nom LIKE :search_nom
                    OR u.prenom LIKE :search_prenom
                    OR u.email LIKE :search_email
                    OR CONCAT(u.prenom, ' ', u.nom) LIKE :search_full_1
                    OR CONCAT(u.nom, ' ', u.prenom) LIKE :search_full_2
                )
            ";

            $searchValue = '%' . $search . '%';
            $countParams['search_nom'] = $searchValue;
            $countParams['search_prenom'] = $searchValue;
            $countParams['search_email'] = $searchValue;
            $countParams['search_full_1'] = $searchValue;
            $countParams['search_full_2'] = $searchValue;
        }

        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $totalApplications = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($totalApplications / self::PER_PAGE));

        if ($currentPage > $totalPages) {
            $currentPage = $totalPages;
        }

        $offset = ($currentPage - 1) * self::PER_PAGE;

        $sql = "
            SELECT
                c.id,
                c.status,
                c.created_at,
                u.nom,
                u.prenom,
                u.email,
                o.titre,
                p.label AS promotion_label
            FROM candidatures c
            INNER JOIN users u ON u.id = c.student_user_id
            INNER JOIN offres o ON o.id = c.offre_id
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN promotions p ON p.id = sp.promotion_id
            WHERE 1 = 1
        ";

        $params = [];

        if ($selectedPromotionId !== null) {
            $sql .= " AND sp.promotion_id = :promotion_id ";
            $params['promotion_id'] = $selectedPromotionId;
        }

        if ($search !== '') {
            $sql .= "
                AND (
                    u.nom LIKE :search_nom
                    OR u.prenom LIKE :search_prenom
                    OR u.email LIKE :search_email
                    OR CONCAT(u.prenom, ' ', u.nom) LIKE :search_full_1
                    OR CONCAT(u.nom, ' ', u.prenom) LIKE :search_full_2
                )
            ";

            $searchValue = '%' . $search . '%';
            $params['search_nom'] = $searchValue;
            $params['search_prenom'] = $searchValue;
            $params['search_email'] = $searchValue;
            $params['search_full_1'] = $searchValue;
            $params['search_full_2'] = $searchValue;
        }

        $sql .= "
            ORDER BY
                CASE
                    WHEN c.status = 'envoyee' THEN 1
                    WHEN c.status = 'acceptee' THEN 2
                    WHEN c.status = 'refusee' THEN 3
                    WHEN c.status = 'en_etude' THEN 4
                    ELSE 5
                END,
                c.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $pdo->prepare($sql);

        foreach ($params as $name => $value) {
            if (
                in_array($name, ['search_nom', 'search_prenom', 'search_email', 'search_full_1', 'search_full_2'], true)
            ) {
                $stmt->bindValue(':' . $name, $value, PDO::PARAM_STR);
            } else {
                $stmt->bindValue(':' . $name, (int) $value, PDO::PARAM_INT);
            }
        }

        $stmt->bindValue(':limit', self::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $applications = $stmt->fetchAll();

        return $this->twig->render('pilot-applications.html.twig', [
            'applications' => $applications,
            'promotions' => $promotions,
            'selected_promotion_id' => $selectedPromotionId,
            'search' => $search,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalApplications' => $totalApplications,
        ]);
    }

    public function updateStatus(int $applicationId): void
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $status = trim((string) ($_POST['status'] ?? ''));
        $allowed = ['acceptee', 'refusee'];

        if (!in_array($status, $allowed, true)) {
            http_response_code(400);
            exit('Statut invalide.');
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE candidatures
                SET status = :status
                WHERE id = :id
            ");
            $stmt->execute([
                'status' => $status,
                'id' => $applicationId,
            ]);

            $stmt = $pdo->prepare("
                SELECT student_user_id
                FROM candidatures
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $applicationId]);
            $studentUserId = $stmt->fetchColumn();

            if ($studentUserId) {
                $stmt = $pdo->prepare("
                    SELECT
                        MAX(CASE WHEN status = 'acceptee' THEN 1 ELSE 0 END) AS has_accepted
                    FROM candidatures
                    WHERE student_user_id = :student_user_id
                ");
                $stmt->execute(['student_user_id' => $studentUserId]);
                $summary = $stmt->fetch();

                $newStudentStatus = ((int) ($summary['has_accepted'] ?? 0) === 1)
                    ? 'stage_valide'
                    : 'en_recherche';

                $stmt = $pdo->prepare("
                    UPDATE student_profiles
                    SET status = :status,
                        last_activity = CURDATE()
                    WHERE user_id = :user_id
                ");
                $stmt->execute([
                    'status' => $newStudentStatus,
                    'user_id' => $studentUserId,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            http_response_code(500);
            exit('Erreur lors de la mise à jour.');
        }

        header('Location: /pilot-candidatures');
        exit;
    }
}