<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Support\PilotPromotionAccess;
use Twig\Environment;

class PilotDashboardController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(): string
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'pilote') {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();
        $pilotId = (int) ($_SESSION['user']['id'] ?? 0);
        $allowedPromotionIds = PilotPromotionAccess::getAssignedPromotionIds($pdo, $pilotId);

        $promotionFilterSql = ' AND 1 = 0 ';
        $promotionParams = [];

        if ($allowedPromotionIds !== []) {
            $placeholders = [];
            foreach ($allowedPromotionIds as $index => $promotionId) {
                $key = ':promotion_' . $index;
                $placeholders[] = $key;
                $promotionParams['promotion_' . $index] = $promotionId;
            }
            $promotionFilterSql = ' AND sp.promotion_id IN (' . implode(', ', $placeholders) . ') ';
        }

        $totalStudentsStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM users u
            INNER JOIN student_profiles sp ON sp.user_id = u.id
            WHERE u.role = 'etudiant'
            $promotionFilterSql
        ");
        $totalStudentsStmt->execute($promotionParams);
        $totalStudents = (int) $totalStudentsStmt->fetchColumn();

        $studentsWithoutStageStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM student_profiles sp
            WHERE sp.status = 'sans_stage'
            $promotionFilterSql
        ");
        $studentsWithoutStageStmt->execute($promotionParams);
        $studentsWithoutStage = (int) $studentsWithoutStageStmt->fetchColumn();

        $offersCount = (int) $pdo->query("SELECT COUNT(*) FROM offres")->fetchColumn();

        $applicationsCountStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM candidatures c
            INNER JOIN student_profiles sp ON sp.user_id = c.student_user_id
            WHERE 1 = 1
            $promotionFilterSql
        ");
        $applicationsCountStmt->execute($promotionParams);
        $applicationsCount = (int) $applicationsCountStmt->fetchColumn();

        $validatedStagesStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM student_profiles sp
            WHERE sp.status = 'stage_valide'
            $promotionFilterSql
        ");
        $validatedStagesStmt->execute($promotionParams);
        $validatedStages = (int) $validatedStagesStmt->fetchColumn();

        $recentStudentsStmt = $pdo->prepare("
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
            $promotionFilterSql
            ORDER BY sp.last_activity DESC, u.id DESC
            LIMIT 5
        ");
        $recentStudentsStmt->execute($promotionParams);
        $recentStudents = $recentStudentsStmt->fetchAll();

        return $this->twig->render('pilot-dashboard.html.twig', [
            'total_students' => $totalStudents,
            'students_without_stage' => $studentsWithoutStage,
            'offers_count' => $offersCount,
            'applications_count' => $applicationsCount,
            'validated_stages' => $validatedStages,
            'recent_students' => $recentStudents,
        ]);
    }
}