<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use Twig\Environment;
use PDO;

class OfferDetailController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function show(int $id): string
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT
                o.id,
                o.titre,
                o.entreprise,
                o.lieu,
                o.duree_semaines,
                o.remuneration,
                o.description,
                o.created_at,
                o.entreprise_id,
                e.nom AS entreprise_nom,
                e.siret AS entreprise_siret,
                e.secteur AS entreprise_secteur,
                e.ville AS entreprise_ville,
                e.site_web AS entreprise_site_web,
                e.note AS entreprise_note,
                e.commentaire AS entreprise_commentaire
            FROM offres o
            LEFT JOIN entreprises e ON e.id = o.entreprise_id
            WHERE o.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $offer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$offer) {
            http_response_code(404);
            return 'Offre introuvable.';
        }

        $skillsStmt = $pdo->prepare("
            SELECT c.nom
            FROM offre_competence oc
            INNER JOIN competences c ON c.id = oc.competence_id
            WHERE oc.offre_id = :offre_id
            ORDER BY c.nom ASC
        ");
        $skillsStmt->execute(['offre_id' => $id]);
        $skills = $skillsStmt->fetchAll(PDO::FETCH_ASSOC);

        $isInWishlist = false;
        $hasApplied = false;

        if (isset($_SESSION['user']) && ($_SESSION['user']['role'] ?? null) === 'etudiant') {
            $userId = (int) $_SESSION['user']['id'];

            $wishlistStmt = $pdo->prepare("
                SELECT 1
                FROM student_wishlist
                WHERE user_id = :user_id
                  AND offre_id = :offre_id
                LIMIT 1
            ");
            $wishlistStmt->execute([
                'user_id' => $userId,
                'offre_id' => $id,
            ]);

            $isInWishlist = (bool) $wishlistStmt->fetchColumn();

            $applicationStmt = $pdo->prepare("
                SELECT 1
                FROM candidatures
                WHERE student_user_id = :user_id
                  AND offre_id = :offre_id
                LIMIT 1
            ");
            $applicationStmt->execute([
                'user_id' => $userId,
                'offre_id' => $id,
            ]);

            $hasApplied = (bool) $applicationStmt->fetchColumn();
        }

        return $this->twig->render('offer-detail.html.twig', [
            'offer' => $offer,
            'skills' => $skills,
            'is_in_wishlist' => $isInWishlist,
            'has_applied' => $hasApplied,
        ]);
    }
}