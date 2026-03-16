<?php

namespace App\Controller;

use App\Database;
use Twig\Environment;

class PilotApplicationsController
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

        $stmt = $pdo->query("
            SELECT
                c.id,
                c.status,
                c.created_at,
                u.nom,
                u.prenom,
                u.email,
                o.titre
            FROM candidatures c
            INNER JOIN users u ON u.id = c.student_user_id
            INNER JOIN offres o ON o.id = c.offre_id
            ORDER BY c.created_at DESC
        ");

        $applications = $stmt->fetchAll();

        return $this->twig->render('pilot-applications.html.twig', [
            'site_name' => 'Help Me Stage',
            'user' => $_SESSION['user'],
            'applications' => $applications
        ]);
    }
}