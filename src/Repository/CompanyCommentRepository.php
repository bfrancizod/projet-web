<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Repository des commentaires d'entreprises (table : entreprise_commentaires)
 *
 * Chaque utilisateur peut laisser un seul commentaire par entreprise.
 * La méthode upsert() gère automatiquement la création ou la mise à jour.
 */
class CompanyCommentRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Retourne tous les commentaires d'une entreprise avec le nom/prénom/email
     * de l'auteur — triés du plus récent au plus ancien.
     */
    public function findByCompanyId(int $companyId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                ec.id,
                ec.entreprise_id,
                ec.user_id,
                ec.commentaire,
                ec.created_at,
                u.nom,
                u.prenom,
                u.email
            FROM entreprise_commentaires ec
            LEFT JOIN users u ON u.id = ec.user_id
            WHERE ec.entreprise_id = :entreprise_id
            ORDER BY ec.created_at DESC, ec.id DESC
        ");
        $stmt->execute([
            'entreprise_id' => $companyId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retourne les N derniers commentaires d'une entreprise.
     * bindValue() explicite pour LIMIT car PDO ne supporte pas les entiers
     * nommés avec execute() sur certaines versions MySQL.
     */
    public function findLatestByCompanyId(int $companyId, int $limit = 3): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                ec.id,
                ec.entreprise_id,
                ec.user_id,
                ec.commentaire,
                ec.created_at,
                u.nom,
                u.prenom,
                u.email
            FROM entreprise_commentaires ec
            LEFT JOIN users u ON u.id = ec.user_id
            WHERE ec.entreprise_id = :entreprise_id
            ORDER BY ec.created_at DESC, ec.id DESC
            LIMIT :limit_value
        ");
        $stmt->bindValue(':entreprise_id', $companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit_value', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Compte le nombre total de commentaires d'une entreprise */
    public function countByCompanyId(int $companyId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM entreprise_commentaires
            WHERE entreprise_id = :entreprise_id
        ");
        $stmt->execute([
            'entreprise_id' => $companyId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /** Retrouve le commentaire d'un utilisateur spécifique pour une entreprise — utilisé par upsert() */
    public function findByCompanyIdAndUserId(int $companyId, int $userId): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT
                id,
                entreprise_id,
                user_id,
                commentaire,
                created_at
            FROM entreprise_commentaires
            WHERE entreprise_id = :entreprise_id
              AND user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([
            'entreprise_id' => $companyId,
            'user_id' => $userId,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Crée ou met à jour le commentaire d'un utilisateur pour une entreprise.
     * Un utilisateur ne peut avoir qu'un seul commentaire par entreprise.
     * Si un commentaire existe déjà → UPDATE, sinon → INSERT.
     */
    public function upsert(int $companyId, int $userId, string $commentaire): void
    {
        $existing = $this->findByCompanyIdAndUserId($companyId, $userId);

        if ($existing) {
            $stmt = $this->pdo->prepare("
                UPDATE entreprise_commentaires
                SET commentaire = :commentaire
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $existing['id'],
                'commentaire' => $commentaire,
            ]);

            return;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO entreprise_commentaires (entreprise_id, user_id, commentaire)
            VALUES (:entreprise_id, :user_id, :commentaire)
        ");
        $stmt->execute([
            'entreprise_id' => $companyId,
            'user_id' => $userId,
            'commentaire' => $commentaire,
        ]);
    }

    /** Supprime tous les commentaires d'une entreprise — appelé lors de la suppression de l'entreprise */
    public function deleteByCompanyId(int $companyId): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM entreprise_commentaires
            WHERE entreprise_id = :entreprise_id
        ");
        $stmt->execute([
            'entreprise_id' => $companyId,
        ]);
    }
}