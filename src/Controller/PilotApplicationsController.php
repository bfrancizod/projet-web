<?php

declare(strict_types=1);

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
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
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
            ORDER BY
                CASE
                    WHEN c.status = 'en_etude' THEN 1
                    WHEN c.status = 'envoyee' THEN 2
                    WHEN c.status = 'acceptee' THEN 3
                    WHEN c.status = 'refusee' THEN 4
                    ELSE 5
                END,
                c.created_at DESC
        ");

        $applications = $stmt->fetchAll();

        return $this->twig->render('pilot-applications.html.twig', [
            'applications' => $applications,
        ]);
    }
}