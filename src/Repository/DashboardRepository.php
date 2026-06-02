<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Repository des statistiques pour les tableaux de bord (tables multiples)
 *
 * Centralise les requêtes de comptage et de récupération des données récentes
 * pour les dashboards pilote et admin. La méthode privée buildPromotionFilter()
 * est partagée par toutes les méthodes pilote pour restreindre la visibilité
 * aux promotions assignées au pilote connecté.
 */
class DashboardRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    // =========================
    // PILOT
    // =========================

    /** Compte les étudiants visibles par un pilote selon ses promotions assignées */
    public function countPilotStudents(array $allowedPromotionIds): int
    {
        [$filterSql, $params] = $this->buildPromotionFilter($allowedPromotionIds, 'sp.promotion_id');

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM users u
            INNER JOIN student_profiles sp ON sp.user_id = u.id
            WHERE u.role = 'etudiant'
            $filterSql
        ");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** Compte les étudiants en statut 'sans_stage' dans les promotions du pilote */
    public function countPilotStudentsWithoutStage(array $allowedPromotionIds): int
    {
        [$filterSql, $params] = $this->buildPromotionFilter($allowedPromotionIds, 'sp.promotion_id');

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM student_profiles sp
            WHERE sp.status = 'sans_stage'
            $filterSql
        ");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** Compte toutes les candidatures des étudiants dans les promotions du pilote */
    public function countPilotApplications(array $allowedPromotionIds): int
    {
        [$filterSql, $params] = $this->buildPromotionFilter($allowedPromotionIds, 'sp.promotion_id');

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM candidatures c
            INNER JOIN student_profiles sp ON sp.user_id = c.student_user_id
            WHERE 1 = 1
            $filterSql
        ");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** Compte les étudiants en statut 'stage_valide' dans les promotions du pilote */
    public function countPilotValidatedStages(array $allowedPromotionIds): int
    {
        [$filterSql, $params] = $this->buildPromotionFilter($allowedPromotionIds, 'sp.promotion_id');

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM student_profiles sp
            WHERE sp.status = 'stage_valide'
            $filterSql
        ");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Retourne les N étudiants les plus récemment actifs dans les promotions du pilote.
     * Triés par last_activity DESC pour mettre en avant les étudiants actifs récemment.
     * bindValue() explicite requis car $params contient des entiers (promotions) et LIMIT est entier.
     */
    public function findRecentPilotStudents(array $allowedPromotionIds, int $limit = 5): array
    {
        [$filterSql, $params] = $this->buildPromotionFilter($allowedPromotionIds, 'sp.promotion_id');

        $stmt = $this->pdo->prepare("
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
            WHERE u.role = 'etudiant'
            $filterSql
            ORDER BY sp.last_activity DESC, u.id DESC
            LIMIT :limit
        ");

        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, (int) $value, PDO::PARAM_INT);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // =========================
    // ADMIN
    // =========================

    public function countStudents(): int
    {
        return (int) $this->pdo
            ->query("SELECT COUNT(*) FROM users WHERE role = 'etudiant'")
            ->fetchColumn();
    }

    public function countPilots(): int
    {
        return (int) $this->pdo
            ->query("SELECT COUNT(*) FROM users WHERE role = 'pilote'")
            ->fetchColumn();
    }

    public function countOffers(): int
    {
        return (int) $this->pdo
            ->query("SELECT COUNT(*) FROM offres")
            ->fetchColumn();
    }

    public function countApplications(): int
    {
        return (int) $this->pdo
            ->query("SELECT COUNT(*) FROM candidatures")
            ->fetchColumn();
    }

    public function countPromotions(): int
    {
        return (int) $this->pdo
            ->query("SELECT COUNT(*) FROM promotions")
            ->fetchColumn();
    }

    /** Retourne les N étudiants les plus récemment actifs — pour le dashboard admin */
    public function findRecentStudents(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                u.id,
                u.nom,
                u.prenom,
                u.email,
                sp.formation,
                sp.status,
                sp.last_activity
            FROM users u
            INNER JOIN student_profiles sp ON sp.user_id = u.id
            WHERE u.role = 'etudiant'
            ORDER BY sp.last_activity DESC
            LIMIT :limit
        ");

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // =========================
    // UTIL
    // =========================

    /**
     * Construit dynamiquement le fragment SQL "AND colonne IN (:p0, :p1, ...)"
     * pour filtrer par une liste de promotions autorisées.
     *
     * Si la liste est vide (pilote sans promotion assignée), retourne "AND 1=0"
     * qui ne retourne aucun résultat — comportement intentionnel pour isoler les données.
     *
     * Les placeholders sont générés dynamiquement car PDO ne supporte pas les tableaux
     * dans les requêtes préparées (pas de IN(:array)).
     *
     * @return array{0: string, 1: array} [fragment SQL, paramètres associatifs]
     */
    private function buildPromotionFilter(array $allowedPromotionIds, string $column): array
    {
        if ($allowedPromotionIds === []) {
            return [' AND 1 = 0 ', []];
        }

        $placeholders = [];
        $params = [];

        foreach ($allowedPromotionIds as $index => $promotionId) {
            $key = 'promotion_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (int) $promotionId;
        }

        $sql = ' AND ' . $column . ' IN (' . implode(', ', $placeholders) . ') ';

        return [$sql, $params];
    }
}