<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
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

        $studentsWithoutStage = (int) $pdo->query("
            SELECT COUNT(*)
            FROM student_profiles
            WHERE status = 'sans_stage'
        ")->fetchColumn();

        $offersCount = (int) $pdo->query("
            SELECT COUNT(*)
            FROM offres
        ")->fetchColumn();

        $applicationsCount = (int) $pdo->query("
            SELECT COUNT(*)
            FROM candidatures
        ")->fetchColumn();

        $validatedStages = (int) $pdo->query("
            SELECT COUNT(*)
            FROM student_profiles
            WHERE status = 'stage_valide'
        ")->fetchColumn();

        $recentStudentsStmt = $pdo->query("
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
            ORDER BY sp.last_activity DESC, u.id DESC
            LIMIT 5
        ");
        $recentStudents = $recentStudentsStmt->fetchAll();

        return $this->twig->render('pilot-dashboard.html.twig', [
            'students_without_stage' => $studentsWithoutStage,
            'offers_count' => $offersCount,
            'applications_count' => $applicationsCount,
            'validated_stages' => $validatedStages,
            'recent_students' => $recentStudents,
        ]);
    }
}