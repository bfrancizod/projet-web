<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class PilotPromotionAccess
{
    /**
     * @return int[]
     */
    public static function getAssignedPromotionIds(PDO $pdo, int $pilotId): array
    {
        $stmt = $pdo->prepare("
            SELECT promotion_id
            FROM pilot_promotions
            WHERE pilot_user_id = :pilot_user_id
            ORDER BY promotion_id ASC
        ");
        $stmt->execute([
            'pilot_user_id' => $pilotId,
        ]);

        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_map('intval', $ids ?: []);
    }

    /**
     * @param int[] $promotionIds
     * @return array<int, array<string, mixed>>
     */
    public static function getPromotionsByIds(PDO $pdo, array $promotionIds): array
    {
        if ($promotionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($promotionIds), '?'));

        $stmt = $pdo->prepare("
            SELECT id, label, academic_year, is_active
            FROM promotions
            WHERE id IN ($placeholders)
            ORDER BY academic_year DESC, label ASC
        ");

        foreach (array_values($promotionIds) as $index => $promotionId) {
            $stmt->bindValue($index + 1, $promotionId, PDO::PARAM_INT);
        }

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function pilotCanAccessStudent(PDO $pdo, int $pilotId, int $studentId): bool
    {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM student_profiles sp
            INNER JOIN pilot_promotions pp ON pp.promotion_id = sp.promotion_id
            WHERE sp.user_id = :student_id
              AND pp.pilot_user_id = :pilot_user_id
            LIMIT 1
        ");
        $stmt->execute([
            'student_id' => $studentId,
            'pilot_user_id' => $pilotId,
        ]);

        return (bool) $stmt->fetchColumn();
    }
}