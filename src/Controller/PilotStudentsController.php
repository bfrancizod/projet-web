<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
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

        $selectedPromotionId = isset($_GET['promotion_id']) && ctype_digit((string) $_GET['promotion_id'])
            ? (int) $_GET['promotion_id']
            : null;

        $search = trim((string) ($_GET['q'] ?? ''));

        $promotionsStmt = $pdo->query("
            SELECT id, label
            FROM promotions
            WHERE is_active = 1
            ORDER BY label ASC
        ");
        $promotions = $promotionsStmt->fetchAll();

        $sql = "
            SELECT
                u.id,
                u.nom,
                u.prenom,
                u.email,
                sp.formation,
                sp.status,
                sp.last_activity,
                p.label AS promotion_label
            FROM users u
            INNER JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN promotions p ON p.id = sp.promotion_id
            WHERE u.role = 'etudiant'
        ";

        $params = [];

        if ($selectedPromotionId !== null) {
            $sql .= " AND sp.promotion_id = :promotion_id ";
            $params['promotion_id'] = $selectedPromotionId;
        }

        if ($search !== '') {
            $sql .= "
                AND (
                    u.nom LIKE :search
                    OR u.prenom LIKE :search
                    OR u.email LIKE :search
                    OR CONCAT(u.prenom, ' ', u.nom) LIKE :search
                    OR CONCAT(u.nom, ' ', u.prenom) LIKE :search
                )
            ";
            $params['search'] = '%' . $search . '%';
        }

        $sql .= " ORDER BY u.nom ASC, u.prenom ASC ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $students = $stmt->fetchAll();

        return $this->twig->render('pilot-students.html.twig', [
            'students' => $students,
            'promotions' => $promotions,
            'selected_promotion_id' => $selectedPromotionId,
            'search' => $search,
        ]);
    }
}