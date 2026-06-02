<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Repository des promotions (table : promotions)
 *
 * Une promotion regroupe un ensemble d'étudiants sur une année académique.
 * Elle peut être active (is_active=1) ou archivée.
 * Les pilotes sont rattachés aux promotions via pilot_promotions (N-N).
 */
class PromotionRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Retourne toutes les promotions avec leur nombre d'étudiants et de pilotes.
     * COUNT(DISTINCT ...) est nécessaire car les JOIN peuvent créer des doublons
     * si une promotion a à la fois des étudiants et des pilotes.
     */
    public function findAllWithStats(string $search): array
    {
        $sql = "
            SELECT
                p.id,
                p.label,
                p.academic_year,
                p.is_active,
                COUNT(DISTINCT sp.user_id) AS students_count,
                COUNT(DISTINCT pp.pilot_user_id) AS pilots_count
            FROM promotions p
            LEFT JOIN student_profiles sp ON sp.promotion_id = p.id
            LEFT JOIN pilot_promotions pp ON pp.promotion_id = p.id
            WHERE 1 = 1
        ";

        $params = [];

        if ($search !== '') {
            $sql .= "
                AND (
                    p.label LIKE :search_label
                    OR p.academic_year LIKE :search_year
                )
            ";

            $searchValue = '%' . $search . '%';
            $params['search_label'] = $searchValue;
            $params['search_year'] = $searchValue;
        }

        $sql .= "
            GROUP BY p.id, p.label, p.academic_year, p.is_active
            ORDER BY p.academic_year DESC, p.label ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Retrouve une promotion par son ID — pour pré-remplir le formulaire d'édition */
    public function findById(int $promotionId): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT id, label, academic_year, is_active
            FROM promotions
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $promotionId]);

        return $stmt->fetch();
    }

    /**
     * Vérifie si une promotion avec ce label ET cette année académique existe déjà.
     * Les deux champs combinés forment un identifiant unique (ex: "B1" + "2025-2026").
     * $excludeId permet d'ignorer la promotion en cours d'édition.
     */
    public function existsByLabelAndYear(string $label, string $academicYear, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->pdo->prepare("
                SELECT id
                FROM promotions
                WHERE label = :label
                  AND academic_year = :academic_year
                  AND id != :id
                LIMIT 1
            ");
            $stmt->execute([
                'label' => $label,
                'academic_year' => $academicYear,
                'id' => $excludeId,
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT id
                FROM promotions
                WHERE label = :label
                  AND academic_year = :academic_year
                LIMIT 1
            ");
            $stmt->execute([
                'label' => $label,
                'academic_year' => $academicYear,
            ]);
        }

        return (bool) $stmt->fetch();
    }

    /** Crée une nouvelle promotion et retourne son ID */
    public function create(string $label, string $academicYear, int $isActive): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO promotions (label, academic_year, is_active)
            VALUES (:label, :academic_year, :is_active)
        ");
        $stmt->execute([
            'label' => $label,
            'academic_year' => $academicYear,
            'is_active' => $isActive,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Met à jour le label, l'année et le statut actif d'une promotion */
    public function update(int $promotionId, string $label, string $academicYear, int $isActive): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE promotions
            SET label = :label,
                academic_year = :academic_year,
                is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $promotionId,
            'label' => $label,
            'academic_year' => $academicYear,
            'is_active' => $isActive,
        ]);
    }

    /**
     * Supprime une promotion.
     * Attention : les étudiants et pilotes liés à cette promotion ne sont pas supprimés,
     * mais leur promotion_id devient NULL (ON DELETE SET NULL en BDD).
     */
    public function delete(int $promotionId): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM promotions
            WHERE id = :id
        ");
        $stmt->execute(['id' => $promotionId]);
    }
}