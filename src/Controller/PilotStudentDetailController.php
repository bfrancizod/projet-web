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
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
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
            ORDER BY
                CASE
                    WHEN c.status = 'acceptee' THEN 1
                    WHEN c.status = 'en_etude' THEN 2
                    WHEN c.status = 'envoyee' THEN 3
                    WHEN c.status = 'refusee' THEN 4
                    ELSE 5
                END,
                c.created_at DESC
        ");
        $stmt->execute(['id' => $studentId]);
        $applications = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT
                o.id,
                o.titre,
                o.lieu,
                sw.created_at,
                COALESCE(e.nom, o.entreprise) AS entreprise_nom
            FROM student_wishlist sw
            INNER JOIN offres o ON o.id = sw.offre_id
            LEFT JOIN entreprises e ON e.id = o.entreprise_id
            WHERE sw.user_id = :id
            ORDER BY sw.created_at DESC
        ");
        $stmt->execute(['id' => $studentId]);
        $wishlist = $stmt->fetchAll();

        $acceptedOffer = null;
        $inProgressOffers = [];
        $sentOffers = [];

        foreach ($applications as $application) {
            if ($application['status'] === 'acceptee' && $acceptedOffer === null) {
                $acceptedOffer = $application;
            } elseif ($application['status'] === 'en_etude') {
                $inProgressOffers[] = $application;
            } elseif ($application['status'] === 'envoyee') {
                $sentOffers[] = $application;
            }
        }

        $hasFoundStage = $acceptedOffer !== null;
        $hasInProgressStage = count($inProgressOffers) > 0;

        return $this->twig->render('pilot-student-detail.html.twig', [
            'student' => $student,
            'applications' => $applications,
            'wishlist' => $wishlist,
            'accepted_offer' => $acceptedOffer,
            'in_progress_offers' => $inProgressOffers,
            'sent_offers' => $sentOffers,
            'has_found_stage' => $hasFoundStage,
            'has_in_progress_stage' => $hasInProgressStage,
        ]);
    }
}