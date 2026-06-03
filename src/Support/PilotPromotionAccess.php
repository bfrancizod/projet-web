<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Classe utilitaire statique pour la gestion des accès pilote / promotions.
 *
 * Un pilote est associé à une ou plusieurs promotions via la table pivot pilot_promotions.
 * Cette classe centralise les requêtes liées à cette relation pour éviter de les dupliquer
 * dans AdminPilotController et PilotStudentController.
 *
 * Déclarée `final` : pas destinée à être étendue.
 * Toutes les méthodes sont statiques : pas d'état interne, la connexion PDO est passée en paramètre.
 */
final class PilotPromotionAccess
{
    /**
     * Retourne la liste des IDs de promotions assignées à un pilote.
     *
     * Utilisé pour pré-cocher les checkboxes dans le formulaire d'édition du pilote.
     * FETCH_COLUMN retourne directement un tableau de valeurs (colonne unique),
     * évitant un array_column() supplémentaire.
     *
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

        // array_map('intval') force le typage entier (PDO peut retourner des strings selon le driver)
        return array_map('intval', $ids ?: []);
    }

    /**
     * Charge les données complètes des promotions à partir de leurs IDs.
     *
     * Utilisé pour afficher les labels de promotions d'un pilote (dashboard, formulaires).
     * La liste de placeholders ? est construite dynamiquement pour éviter l'injection SQL
     * tout en restant compatible avec IN() qui ne supporte pas un paramètre unique type tableau.
     * bindValue() avec PDO::PARAM_INT garantit que les valeurs sont traitées comme entiers.
     *
     * @param int[] $promotionIds
     * @return array<int, array<string, mixed>>
     */
    public static function getPromotionsByIds(PDO $pdo, array $promotionIds): array
    {
        if ($promotionIds === []) {
            return [];
        }

        // Génère autant de '?' que d'IDs : "?,?,?" pour 3 IDs
        $placeholders = implode(',', array_fill(0, count($promotionIds), '?'));

        $stmt = $pdo->prepare("
            SELECT id, label, academic_year, is_active
            FROM promotions
            WHERE id IN ($placeholders)
            ORDER BY academic_year DESC, label ASC
        ");

        // bindValue() avec index 1-based (PDO positionnel commence à 1, pas 0)
        foreach (array_values($promotionIds) as $index => $promotionId) {
            $stmt->bindValue($index + 1, $promotionId, PDO::PARAM_INT);
        }

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Vérifie si un pilote a le droit d'agir sur une candidature.
     *
     * Une candidature est accessible uniquement si l'étudiant qui l'a soumise
     * appartient à une promotion assignée au pilote.
     * Jointure : candidatures → student_profiles → pilot_promotions.
     * SELECT 1 + LIMIT 1 est plus léger qu'un COUNT(*) : on s'arrête au premier résultat trouvé.
     */
    public static function pilotCanAccessApplication(PDO $pdo, int $pilotId, int $applicationId): bool
    {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM candidatures c
            INNER JOIN student_profiles sp ON sp.user_id = c.student_user_id
            INNER JOIN pilot_promotions pp ON pp.promotion_id = sp.promotion_id
            WHERE c.id = :application_id
              AND pp.pilot_user_id = :pilot_user_id
            LIMIT 1
        ");
        $stmt->execute([
            'application_id' => $applicationId,
            'pilot_user_id'  => $pilotId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Vérifie si un pilote a le droit d'accéder au profil d'un étudiant.
     *
     * Un pilote peut voir un étudiant uniquement si celui-ci appartient à une des promotions
     * qui lui sont assignées. La jointure entre student_profiles et pilot_promotions via
     * promotion_id implémente cette règle directement en SQL.
     * SELECT 1 + LIMIT 1 est plus léger qu'un COUNT(*) : on s'arrête au premier résultat trouvé.
     */
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

        // fetchColumn() retourne la valeur '1' si une ligne existe, false sinon
        return (bool) $stmt->fetchColumn();
    }
}