<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Repository des pilotes de formation (table : users avec role='pilote')
 *
 * Les pilotes sont liés à leurs promotions via la table pivot pilot_promotions (N-N).
 * La méthode savePilot() gère création et édition avec mise à jour des promotions
 * via une stratégie delete+insert dans une transaction.
 */
class PilotRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Retourne tous les pilotes avec leurs promotions concaténées en une chaîne.
     * GROUP_CONCAT agrège les labels de promotions (ex: "B1 (2025-2026), B2 (2025-2026)").
     * COALESCE(..., '') retourne une chaîne vide si le pilote n'a aucune promotion assignée.
     */
    public function findAll(string $search): array
    {
        $sql = "
            SELECT
                u.id,
                u.nom,
                u.prenom,
                u.email,
                u.created_at,
                COALESCE(
                    GROUP_CONCAT(
                        DISTINCT CONCAT(p.label, ' (', p.academic_year, ')')
                        ORDER BY p.academic_year DESC, p.label ASC
                        SEPARATOR ', '
                    ),
                    ''
                ) AS promotions_labels
            FROM users u
            LEFT JOIN pilot_promotions pp ON pp.pilot_user_id = u.id
            LEFT JOIN promotions p ON p.id = pp.promotion_id
            WHERE u.role = 'pilote'
        ";

        $params = [];

        if ($search !== '') {
            $sql .= "
                AND (
                    u.nom LIKE :search_nom
                    OR u.prenom LIKE :search_prenom
                    OR u.email LIKE :search_email
                )
            ";

            $searchValue = '%' . $search . '%';
            $params['search_nom'] = $searchValue;
            $params['search_prenom'] = $searchValue;
            $params['search_email'] = $searchValue;
        }

        $sql .= "
            GROUP BY u.id, u.nom, u.prenom, u.email, u.created_at
            ORDER BY u.nom ASC, u.prenom ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Retourne les promotions actives pour alimenter les checkboxes du formulaire pilote */
    public function getActivePromotions(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, label, academic_year
            FROM promotions
            WHERE is_active = 1
            ORDER BY academic_year DESC, label ASC
        ");

        return $stmt->fetchAll();
    }

    /** Retrouve un pilote par son ID en vérifiant le rôle — pour le formulaire d'édition */
    public function findById(int $pilotId): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT id, nom, prenom, email
            FROM users
            WHERE id = :id
              AND role = 'pilote'
            LIMIT 1
        ");
        $stmt->execute(['id' => $pilotId]);

        return $stmt->fetch();
    }

    /**
     * Vérifie si un email est déjà utilisé par un autre compte.
     * $excludePilotId permet d'ignorer le pilote en cours d'édition pour éviter un faux positif.
     */
    public function emailExists(string $email, ?int $excludePilotId = null): bool
    {
        if ($excludePilotId !== null) {
            $stmt = $this->pdo->prepare("
                SELECT id
                FROM users
                WHERE email = :email
                  AND id != :id
                LIMIT 1
            ");
            $stmt->execute([
                'email' => $email,
                'id' => $excludePilotId,
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT id
                FROM users
                WHERE email = :email
                LIMIT 1
            ");
            $stmt->execute([
                'email' => $email,
            ]);
        }

        return (bool) $stmt->fetch();
    }

    /**
     * Crée ou met à jour un pilote avec ses promotions dans une transaction.
     *
     * Gestion des promotions (stratégie delete+insert) :
     * - Supprime toutes les liaisons pilot_promotions existantes pour ce pilote
     * - Réinsère les nouvelles liaisons une par une
     * Cette approche est plus simple qu'un diff et reste cohérente pour les listes courtes.
     *
     * En édition, le mot de passe n'est pas modifié ($password = null ignoré).
     * En création, password_hash() avec PASSWORD_DEFAULT (bcrypt) est utilisé.
     */
    public function savePilot(
        ?int $pilotId,
        string $nom,
        string $prenom,
        string $email,
        ?string $password,
        array $promotionIds
    ): int {
        $isEdit = $pilotId !== null;

        $this->pdo->beginTransaction();

        try {
            if ($isEdit) {
                $stmt = $this->pdo->prepare("
                    UPDATE users
                    SET nom = :nom,
                        prenom = :prenom,
                        email = :email
                    WHERE id = :id
                      AND role = 'pilote'
                ");
                $stmt->execute([
                    'id' => $pilotId,
                    'nom' => $nom,
                    'prenom' => $prenom,
                    'email' => $email,
                ]);

                $finalPilotId = $pilotId;
            } else {
                $stmt = $this->pdo->prepare("
                    INSERT INTO users (nom, prenom, email, password_hash, role)
                    VALUES (:nom, :prenom, :email, :password_hash, 'pilote')
                ");
                $stmt->execute([
                    'nom' => $nom,
                    'prenom' => $prenom,
                    'email' => $email,
                    'password_hash' => password_hash((string) $password, PASSWORD_DEFAULT),
                ]);

                $finalPilotId = (int) $this->pdo->lastInsertId();
            }

            $deleteStmt = $this->pdo->prepare("
                DELETE FROM pilot_promotions
                WHERE pilot_user_id = :pilot_user_id
            ");
            $deleteStmt->execute([
                'pilot_user_id' => $finalPilotId,
            ]);

            $insertStmt = $this->pdo->prepare("
                INSERT INTO pilot_promotions (pilot_user_id, promotion_id)
                VALUES (:pilot_user_id, :promotion_id)
            ");

            foreach ($promotionIds as $promotionId) {
                $insertStmt->execute([
                    'pilot_user_id' => $finalPilotId,
                    'promotion_id' => $promotionId,
                ]);
            }

            $this->pdo->commit();

            return $finalPilotId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Supprime un pilote en vérifiant son rôle dans la requête.
     * Les liaisons pilot_promotions sont supprimées automatiquement par ON DELETE CASCADE en BDD.
     */
    public function delete(int $pilotId): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM users
            WHERE id = :id
              AND role = 'pilote'
        ");
        $stmt->execute(['id' => $pilotId]);
    }
}