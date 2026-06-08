<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 *
 * Gère toutes les opérations BDD liées aux candidatures des étudiants :
 * création, recherche paginée, mise à jour du statut, et vérification de doublon.
 */
class ApplicationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Retourne toutes les promotions actives pour alimenter les filtres */
    public function getActivePromotions(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, label
            FROM promotions
            WHERE is_active = 1
            ORDER BY label ASC
        ");

        return $stmt->fetchAll();
    }

    /**
     * Compte le total de candidatures selon les filtres — utilisé pour calculer le nombre de pages.
     */
    public function countApplications(?int $selectedPromotionId, string $search, array $allowedPromotionIds = []): int
    {
        $sql = "
            SELECT COUNT(*)
            FROM candidatures c
            INNER JOIN users u ON u.id = c.student_user_id
            INNER JOIN offres o ON o.id = c.offre_id
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN promotions p ON p.id = sp.promotion_id
            WHERE 1 = 1
        ";

        $params = [];

        // Restriction aux promotions du pilote — ignorée pour les admins (tableau vide)
        // Paramètres nommés :allowed_prom_0, :allowed_prom_1… pour rester cohérent avec le reste de la requête
        if ($allowedPromotionIds !== []) {
            $placeholders = [];
            foreach ($allowedPromotionIds as $i => $id) {
                $key = 'allowed_prom_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $id;
            }
            $sql .= ' AND sp.promotion_id IN (' . implode(',', $placeholders) . ') ';
        }

        if ($selectedPromotionId !== null) {
            $sql .= " AND sp.promotion_id = :promotion_id ";
            $params['promotion_id'] = $selectedPromotionId;
        }

        if ($search !== '') {
            $sql .= "
                AND (
                    u.nom LIKE :search_nom
                    OR u.prenom LIKE :search_prenom
                    OR u.email LIKE :search_email
                    OR CONCAT(u.prenom, ' ', u.nom) LIKE :search_full_1
                    OR CONCAT(u.nom, ' ', u.prenom) LIKE :search_full_2
                )
            ";

            $searchValue = '%' . $search . '%';
            $params['search_nom'] = $searchValue;
            $params['search_prenom'] = $searchValue;
            $params['search_email'] = $searchValue;
            $params['search_full_1'] = $searchValue;
            $params['search_full_2'] = $searchValue;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Retourne une page de candidatures avec les infos étudiant, offre et promotion.
     * Le tri prioritise les candidatures "envoyées" (non traitées) en haut de liste,

     */
    public function findApplicationsPaginated(
        ?int $selectedPromotionId,
        string $search,
        int $limit,
        int $offset,
        array $allowedPromotionIds = []
    ): array {
        $sql = "
            SELECT
                c.id,
                c.status,
                c.created_at,
                u.nom,
                u.prenom,
                u.email,
                o.titre,
                p.label AS promotion_label
            FROM candidatures c
            INNER JOIN users u ON u.id = c.student_user_id
            INNER JOIN offres o ON o.id = c.offre_id
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN promotions p ON p.id = sp.promotion_id
            WHERE 1 = 1
        ";

        $params = [];

        // Restriction aux promotions du pilote — ignorée pour les admins (tableau vide)
        if ($allowedPromotionIds !== []) {
            $placeholders = [];
            foreach ($allowedPromotionIds as $i => $id) {
                $key = 'allowed_prom_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $id;
            }
            $sql .= ' AND sp.promotion_id IN (' . implode(',', $placeholders) . ') ';
        }

        if ($selectedPromotionId !== null) {
            $sql .= " AND sp.promotion_id = :promotion_id ";
            $params['promotion_id'] = $selectedPromotionId;
        }

        if ($search !== '') {
            $sql .= "
                AND (
                    u.nom LIKE :search_nom
                    OR u.prenom LIKE :search_prenom
                    OR u.email LIKE :search_email
                    OR CONCAT(u.prenom, ' ', u.nom) LIKE :search_full_1
                    OR CONCAT(u.nom, ' ', u.prenom) LIKE :search_full_2
                )
            ";

            $searchValue = '%' . $search . '%';
            $params['search_nom'] = $searchValue;
            $params['search_prenom'] = $searchValue;
            $params['search_email'] = $searchValue;
            $params['search_full_1'] = $searchValue;
            $params['search_full_2'] = $searchValue;
        }

        $sql .= "
            ORDER BY
                CASE
                    WHEN c.status = 'envoyee' THEN 1
                    WHEN c.status = 'acceptee' THEN 2
                    WHEN c.status = 'refusee' THEN 3
                    WHEN c.status = 'en_etude' THEN 4
                    ELSE 5
                END,
                c.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $name => $value) {
            if (
                in_array($name, ['search_nom', 'search_prenom', 'search_email', 'search_full_1', 'search_full_2'], true)
            ) {
                $stmt->bindValue(':' . $name, $value, PDO::PARAM_STR);
            } else {
                $stmt->bindValue(':' . $name, (int) $value, PDO::PARAM_INT);
            }
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Met à jour le statut d'une candidature ET synchronise le statut de l'étudiant.
     */
    public function updateApplicationStatusAndStudentProfile(int $applicationId, string $status): void
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                UPDATE candidatures
                SET status = :status
                WHERE id = :id
            ");
            $stmt->execute([
                'status' => $status,
                'id' => $applicationId,
            ]);

            $stmt = $this->pdo->prepare("
                SELECT student_user_id
                FROM candidatures
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $applicationId]);
            $studentUserId = $stmt->fetchColumn();

            if ($studentUserId) {
                $stmt = $this->pdo->prepare("
                    SELECT
                        MAX(CASE WHEN status = 'acceptee' THEN 1 ELSE 0 END) AS has_accepted
                    FROM candidatures
                    WHERE student_user_id = :student_user_id
                ");
                $stmt->execute(['student_user_id' => $studentUserId]);
                $summary = $stmt->fetch();

                $newStudentStatus = ((int) ($summary['has_accepted'] ?? 0) === 1)
                    ? 'stage_valide'
                    : 'en_recherche';

                $stmt = $this->pdo->prepare("
                    UPDATE student_profiles
                    SET status = :status,
                        last_activity = CURDATE()
                    WHERE user_id = :user_id
                ");
                $stmt->execute([
                    'status' => $newStudentStatus,
                    'user_id' => $studentUserId,
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /** Retrouve une offre par son ID — utilisé pour vérifier qu'elle existe avant candidature */
    public function findOfferById(int $offerId): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM offres
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $offerId]);

        return $stmt->fetch();
    }

    /** Vérifie si l'étudiant a déjà postulé à cette offre */
    public function hasStudentAppliedToOffer(int $userId, int $offerId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM candidatures
            WHERE student_user_id = :user_id
              AND offre_id = :offer_id
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'offer_id' => $offerId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    /** Crée une nouvelle candidature avec le statut initial 'envoyee' */
    public function createApplication(
        int $userId,
        int $offerId,
        string $lettreMotivation,
        string $cvFilename
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO candidatures (
                student_user_id,
                offre_id,
                status,
                lettre_motivation,
                cv_filename
            )
            VALUES (
                :user_id,
                :offer_id,
                'envoyee',
                :lettre_motivation,
                :cv_filename
            )
        ");

        $stmt->execute([
            'user_id' => $userId,
            'offer_id' => $offerId,
            'lettre_motivation' => $lettreMotivation,
            'cv_filename' => $cvFilename,
        ]);
    }

    /** Retourne toutes les candidatures d'un étudiant avec le titre et l'entreprise de l'offre */
    public function findApplicationsByStudentUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                c.id,
                c.status,
                c.created_at,
                o.id AS offre_id,
                o.titre,
                o.entreprise
            FROM candidatures c
            INNER JOIN offres o ON o.id = c.offre_id
            WHERE c.student_user_id = :user_id
            ORDER BY c.created_at DESC
        ");
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /** Compte le nombre total de candidatures d'un étudiant — utilisé dans les statistiques dashboard */
    public function countApplicationsByStudentUserId(int $userId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM candidatures
            WHERE student_user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /** Retourne les N dernières candidatures d'un étudiant — affiché dans le dashboard étudiant */
    public function findRecentApplicationsByStudentUserId(int $userId, int $limit = 5): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                c.id,
                c.status,
                c.created_at,
                o.id AS offre_id,
                o.titre,
                o.entreprise
            FROM candidatures c
            INNER JOIN offres o ON o.id = c.offre_id
            WHERE c.student_user_id = :user_id
            ORDER BY c.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}