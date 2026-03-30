<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use Twig\Environment;

class AdminDashboardController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(): string
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'administrateur') {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();

        $offersCount = (int) $pdo->query("SELECT COUNT(*) FROM offres")->fetchColumn();
        $studentsCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'etudiant'")->fetchColumn();
        $pilotsCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'pilote'")->fetchColumn();
        $applicationsCount = (int) $pdo->query("SELECT COUNT(*) FROM candidatures")->fetchColumn();

        $recentStudentsStmt = $pdo->query("
            SELECT
                u.id,
                u.nom,
                u.prenom,
                u.email,
                sp.formation,
                sp.last_activity
            FROM users u
            INNER JOIN student_profiles sp ON sp.user_id = u.id
            WHERE u.role = 'etudiant'
            ORDER BY sp.last_activity DESC, u.id DESC
            LIMIT 5
        ");

        return $this->twig->render('admin-dashboard.html.twig', [
            'offers_count' => $offersCount,
            'students_count' => $studentsCount,
            'pilots_count' => $pilotsCount,
            'applications_count' => $applicationsCount,
            'recent_students' => $recentStudentsStmt->fetchAll(),
        ]);
    }
}