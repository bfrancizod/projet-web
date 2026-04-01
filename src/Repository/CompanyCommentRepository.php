<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

class CompanyCommentRepository
{
    public function __construct(private PDO $pdo)
    {
    }

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