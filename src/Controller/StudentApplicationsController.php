<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use Twig\Environment;

class StudentApplicationsController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(): string
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'etudiant') {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();
        $userId = (int) $_SESSION['user']['id'];

        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.status,
                c.created_at,
                o.id AS offre_id,
                o.titre,
                o.entreprise
            FROM candidatures c
            INNER JOIN offres o ON o.id = c.offre_id
            WHERE c.student_user_id = :user_id
            ORDER BY c.created_at DESC
        ");
        $stmt->execute(['user_id' => $userId]);
        $applications = $stmt->fetchAll();

        return $this->twig->render('student-applications.html.twig', [
            'site_name' => 'Help Me Stage',
            'applications' => $applications,
        ]);
    }
}