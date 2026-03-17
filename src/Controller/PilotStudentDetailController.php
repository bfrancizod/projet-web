<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use Twig\Environment;

class PilotStudentDetailController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function show(int $studentId): string
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'pilote') {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
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
            WHERE u.id = :id
              AND u.role = 'etudiant'
            LIMIT 1
        ");
        $stmt->execute(['id' => $studentId]);
        $student = $stmt->fetch();

        if (!$student) {
            http_response_code(404);
            return 'Étudiant introuvable.';
        }

        $stmt = $pdo->prepare("
            SELECT
                c.status,
                c.created_at,
                c.lettre_motivation,
                c.cv_filename,
                o.titre,
                o.entreprise
            FROM candidatures c
            INNER JOIN offres o ON o.id = c.offre_id
            WHERE c.student_user_id = :id
            ORDER BY c.created_at DESC
        ");
        $stmt->execute(['id' => $studentId]);
        $applications = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT
                o.titre,
                o.entreprise,
                o.lieu
            FROM student_wishlist sw
            INNER JOIN offres o ON o.id = sw.offre_id
            WHERE sw.user_id = :id
            ORDER BY sw.created_at DESC
        ");
        $stmt->execute(['id' => $studentId]);
        $wishlist = $stmt->fetchAll();

        return $this->twig->render('pilot-student-detail.html.twig', [
            'student' => $student,
            'applications' => $applications,
            'wishlist' => $wishlist,
        ]);
    }
}