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

        $stats = [
            'offers' => 0,
            'students' => 0,
            'pilots' => 0,
            'applications' => 0,
        ];

        $stats['offers'] = (int) $pdo->query("SELECT COUNT(*) FROM offres")->fetchColumn();
        $stats['students'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'etudiant'")->fetchColumn();
        $stats['pilots'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'pilote'")->fetchColumn();
        $stats['applications'] = (int) $pdo->query("SELECT COUNT(*) FROM candidatures")->fetchColumn();

        $recentStudentsStmt = $pdo->query("
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
            ORDER BY sp.last_activity DESC, u.id DESC
            LIMIT 5
        ");
        $recentStudents = $recentStudentsStmt->fetchAll();

        $recentPilotsStmt = $pdo->query("
            SELECT
                id,
                nom,
                prenom,
                email,
                created_at
            FROM users
            WHERE role = 'pilote'
            ORDER BY id DESC
            LIMIT 5
        ");
        $recentPilots = $recentPilotsStmt->fetchAll();

        return $this->twig->render('admin-dashboard.html.twig', [
            'stats' => $stats,
            'recent_students' => $recentStudents,
            'recent_pilots' => $recentPilots,
        ]);
    }
}