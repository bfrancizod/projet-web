<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Support\PilotPromotionAccess;
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
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();
        $currentUserRole = $_SESSION['user']['role'] ?? null;

        if ($currentUserRole === 'pilote') {
            $pilotId = (int) ($_SESSION['user']['id'] ?? 0);
            if (!PilotPromotionAccess::pilotCanAccessStudent($pdo, $pilotId, $studentId)) {
                http_response_code(403);
                return 'Accès refusé à cet étudiant.';
            }
        }

        $stmt = $pdo->prepare("
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
                c.id,
                c.status,
                c.created_at,
                c.lettre_motivation,
                c.cv_filename,
                o.id AS offre_id,
                o.titre,
                o.lieu,
                o.remuneration,
                o.duree_semaines,
                COALESCE(e.nom, o.entreprise) AS entreprise_nom
            FROM candidatures c
            INNER JOIN offres o ON o.id = c.offre_id
            LEFT JOIN entreprises e ON e.id = o.entreprise_id
            WHERE c.student_user_id = :id
            ORDER BY c.created_at DESC
        ");
        $stmt->execute(['id' => $studentId]);
        $applications = $stmt->fetchAll();

        return $this->twig->render('pilot-student-detail.html.twig', [
            'student' => $student,
            'applications' => $applications,
        ]);
    }
}