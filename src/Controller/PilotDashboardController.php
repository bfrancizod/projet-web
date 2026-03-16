<?php

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
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pilote') {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();

        $studentsWithoutInternship = (int) $pdo->query("
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

        $validatedInternships = (int) $pdo->query("
            SELECT COUNT(*)
            FROM student_profiles
            WHERE status = 'stage_valide'
        ")->fetchColumn();

        $stmt = $pdo->query("
            SELECT
                u.id,
                u.nom,
                u.prenom,
                u.email,
                sp.formation,
                sp.status,
                sp.last_activity
            FROM users u
            INNER JOIN student_profiles sp ON sp.user_id = u.id
            WHERE u.role = 'etudiant'
            ORDER BY sp.last_activity DESC
            LIMIT 4
        ");

        $recentStudents = $stmt->fetchAll();

        return $this->twig->render('pilot-dashboard.html.twig', [
            'site_name' => 'Help Me Stage',
            'user' => $_SESSION['user'],
            'stats' => [
                'sans_stage' => $studentsWithoutInternship,
                'offres' => $offersCount,
                'candidatures' => $applicationsCount,
                'valides' => $validatedInternships,
            ],
            'recent_students' => $recentStudents,
        ]);
    }
}