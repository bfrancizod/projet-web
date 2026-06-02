<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Repository des étudiants (tables : users + student_profiles)
 *
 * Un étudiant est stocké sur deux tables liées :
 * - users : identité (nom, prénom, email, mot de passe hashé, rôle)
 * - student_profiles : données pédagogiques (formation, promotion, statut de stage)
 * Les créations et suppressions utilisent des transactions pour garantir la cohérence.
 */
class StudentRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Retourne toutes les promotions (actives et inactives) — pour les formulaires d'admin */
    public function getAllPromotions(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, label, academic_year
            FROM promotions
            ORDER BY academic_year DESC, label ASC
        ");

        return $stmt->fetchAll();
    }

    /** Retourne uniquement les promotions actives — pour les formulaires pilote/étudiant */
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
     * Compte les étudiants visibles selon le rôle de l'utilisateur connecté.
     * Un pilote ne voit que les étudiants de ses promotions (liste $allowedPromotionIds).
     * Si un pilote n'a aucune promotion assignée, "AND 1=0" retourne 0 résultat intentionnellement.
     */
    public function countStudents(
        string $currentUserRole,
        array $allowedPromotionIds,
        ?int $selectedPromotionId,
        string $search
    ): int {
        $sql = "
            SELECT COUNT(*)
            FROM users u
            INNER JOIN student_profiles sp ON sp.user_id = u.id
            WHERE u.role = 'etudiant'
        ";

        $params = [];

        if ($currentUserRole === 'pilote') {
            if ($allowedPromotionIds === []) {
                $sql .= " AND 1 = 0 ";
            } else {
                $placeholders = [];
                foreach ($allowedPromotionIds as $index => $promotionId) {
                    $key = ':allowed_promotion_' . $index;
                    $placeholders[] = $key;
                    $params['allowed_promotion_' . $index] = (int) $promotionId;
                }

                $sql .= " AND sp.promotion_id IN (" . implode(', ', $placeholders) . ")";
            }
        }

        if ($selectedPromotionId !== null) {
            $sql .= " AND sp.promotion_id = :promotion_id";
            $params['promotion_id'] = $selectedPromotionId;
        }

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

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $name => $value) {
            if (in_array($name, ['search_nom', 'search_prenom', 'search_email'], true)) {
                $stmt->bindValue(':' . $name, $value, PDO::PARAM_STR);
            } else {
                $stmt->bindValue(':' . $name, (int) $value, PDO::PARAM_INT);
            }
        }

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Retourne une page d'étudiants filtrés et triés par promotion/nom.
     * Même logique de restriction par rôle que countStudents().
     * bindValue() explicite est nécessaire pour LIMIT/OFFSET car PDO ne supporte pas
     * les paramètres nommés entiers avec execute() dans certaines versions MySQL.
     */
    public function findStudentsPaginated(
        string $currentUserRole,
        array $allowedPromotionIds,
        ?int $selectedPromotionId,
        string $search,
        int $limit,
        int $offset
    ): array {
        $sql = "
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
        ";

        $params = [];

        if ($currentUserRole === 'pilote') {
            if ($allowedPromotionIds === []) {
                $sql .= " AND 1 = 0 ";
            } else {
                $placeholders = [];
                foreach ($allowedPromotionIds as $index => $promotionId) {
                    $key = ':allowed_promotion_' . $index;
                    $placeholders[] = $key;
                    $params['allowed_promotion_' . $index] = (int) $promotionId;
                }

                $sql .= " AND sp.promotion_id IN (" . implode(', ', $placeholders) . ")";
            }
        }

        if ($selectedPromotionId !== null) {
            $sql .= " AND sp.promotion_id = :promotion_id";
            $params['promotion_id'] = $selectedPromotionId;
        }

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
            ORDER BY p.academic_year DESC, p.label ASC, u.nom ASC, u.prenom ASC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $name => $value) {
            if (in_array($name, ['search_nom', 'search_prenom', 'search_email'], true)) {
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

    /** Retrouve un étudiant avec ses infos promotion — pour l'affichage en lecture seule */
    public function findStudentById(int $studentId): array|false
    {
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
            WHERE u.id = :id
              AND u.role = 'etudiant'
            LIMIT 1
        ");
        $stmt->execute(['id' => $studentId]);

        return $stmt->fetch();
    }

    /** Retrouve un étudiant avec promotion_id brut — pour pré-remplir le formulaire d'édition */
    public function findStudentForEdit(int $studentId): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT
                u.id,
                u.nom,
                u.prenom,
                u.email,
                sp.formation,
                sp.promotion_id,
                sp.status,
                sp.last_activity
            FROM users u
            INNER JOIN student_profiles sp ON sp.user_id = u.id
            WHERE u.id = :id
              AND u.role = 'etudiant'
            LIMIT 1
        ");
        $stmt->execute(['id' => $studentId]);

        return $stmt->fetch();
    }

    /**
     * Retourne les candidatures d'un étudiant avec détails de l'offre.
     * COALESCE(e.nom, o.entreprise) : préfère le nom depuis la table entreprises
     * si disponible, sinon utilise le champ texte libre de l'offre.
     */
    public function findApplicationsByStudentId(int $studentId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                c.id,
                c.status,
                c.created_at,
                c.lettre_motivation,
                c.cv_filename,
                o.id AS offre_id,
                o.titre,
                o.lieu,
                o.remuneration,
                o.duree_semaines,
                COALESCE(e.nom, o.entreprise) AS entreprise_nom
            FROM candidatures c
            INNER JOIN offres o ON o.id = c.offre_id
            LEFT JOIN entreprises e ON e.id = o.entreprise_id
            WHERE c.student_user_id = :id
            ORDER BY c.created_at DESC
        ");
        $stmt->execute(['id' => $studentId]);

        return $stmt->fetchAll();
    }

    /** Vérifie qu'une promotion est bien active avant d'y affecter un étudiant */
    public function isActivePromotion(int $promotionId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM promotions
            WHERE id = :id AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute(['id' => $promotionId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Vérifie si un email est déjà utilisé par un autre compte.
     * $excludeStudentId permet d'ignorer l'étudiant en cours d'édition.
     */
    public function emailExists(string $email, ?int $excludeStudentId = null): bool
    {
        if ($excludeStudentId !== null) {
            $stmt = $this->pdo->prepare("
                SELECT id
                FROM users
                WHERE email = :email
                  AND id != :id
                LIMIT 1
            ");
            $stmt->execute([
                'email' => $email,
                'id' => $excludeStudentId,
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
     * Crée un étudiant en deux étapes dans une transaction :
     * 1. Insertion dans users (avec mot de passe hashé via password_hash)
     * 2. Insertion dans student_profiles (avec la date du jour comme last_activity)
     */
    public function createStudent(
        string $nom,
        string $prenom,
        string $email,
        string $password,
        string $formation,
        int $promotionId,
        string $status
    ): int {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO users (nom, prenom, email, password_hash, role)
                VALUES (:nom, :prenom, :email, :password_hash, 'etudiant')
            ");
            $stmt->execute([
                'nom' => $nom,
                'prenom' => $prenom,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);

            $studentId = (int) $this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare("
                INSERT INTO student_profiles (user_id, formation, promotion_id, status, last_activity)
                VALUES (:user_id, :formation, :promotion_id, :status, CURDATE())
            ");
            $stmt->execute([
                'user_id' => $studentId,
                'formation' => $formation,
                'promotion_id' => $promotionId,
                'status' => $status,
            ]);

            $this->pdo->commit();

            return $studentId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Met à jour les deux tables (users + student_profiles) dans une transaction.
     * last_activity est mis à jour automatiquement à la date du jour.
     */
    public function updateStudent(
        int $studentId,
        string $nom,
        string $prenom,
        string $email,
        string $formation,
        int $promotionId,
        string $status
    ): void {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                UPDATE users
                SET nom = :nom,
                    prenom = :prenom,
                    email = :email
                WHERE id = :id
                  AND role = 'etudiant'
            ");
            $stmt->execute([
                'id' => $studentId,
                'nom' => $nom,
                'prenom' => $prenom,
                'email' => $email,
            ]);

            $stmt = $this->pdo->prepare("
                UPDATE student_profiles
                SET formation = :formation,
                    promotion_id = :promotion_id,
                    status = :status,
                    last_activity = CURDATE()
                WHERE user_id = :user_id
            ");
            $stmt->execute([
                'user_id' => $studentId,
                'formation' => $formation,
                'promotion_id' => $promotionId,
                'status' => $status,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Supprime un étudiant et toutes ses données liées dans une transaction.
     * Ordre : wishlist → candidatures → profil → compte utilisateur.
     * Le rôle 'etudiant' est vérifié dans le DELETE final pour éviter
     * de supprimer accidentellement un pilote ou admin.
     */
    public function deleteStudent(int $studentId): void
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('DELETE FROM student_wishlist WHERE user_id = :id');
            $stmt->execute(['id' => $studentId]);

            $stmt = $this->pdo->prepare('DELETE FROM candidatures WHERE student_user_id = :id');
            $stmt->execute(['id' => $studentId]);

            $stmt = $this->pdo->prepare('DELETE FROM student_profiles WHERE user_id = :id');
            $stmt->execute(['id' => $studentId]);

            $stmt = $this->pdo->prepare("
                DELETE FROM users
                WHERE id = :id
                  AND role = 'etudiant'
            ");
            $stmt->execute(['id' => $studentId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}