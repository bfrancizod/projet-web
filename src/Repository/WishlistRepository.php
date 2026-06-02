<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Repository de la wishlist étudiant (table : student_wishlist)
 *
 * Permet aux étudiants de sauvegarder des offres pour les consulter plus tard.
 * La table est une relation N-N entre users et offres.
 */
class WishlistRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Vérifie qu'une offre existe avant de l'ajouter à la wishlist */
    public function offerExists(int $offerId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM offres
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $offerId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Ajoute une offre à la wishlist.
     * INSERT IGNORE évite une erreur si l'offre est déjà dans la wishlist (clé unique).
     */
    public function addOfferToWishlist(int $userId, int $offerId): void
    {
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO student_wishlist (user_id, offre_id)
            VALUES (:user_id, :offre_id)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'offre_id' => $offerId,
        ]);
    }

    /** Retire une offre de la wishlist de l'étudiant */
    public function removeOfferFromWishlist(int $userId, int $offerId): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM student_wishlist
            WHERE user_id = :user_id
              AND offre_id = :offre_id
        ");
        $stmt->execute([
            'user_id' => $userId,
            'offre_id' => $offerId,
        ]);
    }

    /**
     * Retourne les offres de la wishlist avec leurs détails.
     * COALESCE(e.nom, o.entreprise, 'Entreprise non définie') : priorité au nom
     * de la table entreprises, sinon le champ texte libre de l'offre.
     */
    public function findWishlistOffersByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                o.id,
                o.titre,
                COALESCE(e.nom, o.entreprise, 'Entreprise non définie') AS entreprise_nom,
                o.lieu,
                o.duree_semaines,
                o.remuneration,
                sw.created_at
            FROM student_wishlist sw
            INNER JOIN offres o ON o.id = sw.offre_id
            LEFT JOIN entreprises e ON e.id = o.entreprise_id
            WHERE sw.user_id = :user_id
            ORDER BY sw.created_at DESC, o.id DESC
        ");
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Compte les offres en wishlist — utilisé dans les statistiques du dashboard étudiant */
    public function countWishlistOffersByUserId(int $userId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM student_wishlist
            WHERE user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }
}