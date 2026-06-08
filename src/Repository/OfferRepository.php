<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Repository des offres de stage (table : offres)
 *
 * Gère deux contextes distincts :
 * - Vue publique (méthodes findPublic*) : offres avec filtres avancés, compétences, tri
 * - Vue pilote/admin (méthodes sans "Public") : liste simple avec recherche
 *
 * Les offres peuvent référencer une entreprise de deux façons :
 * - Via entreprise_id (lié à la table entreprises)
 * - Via le champ texte libre entreprise (héritage, si l'entreprise n'est pas en BDD)
 * COALESCE(e.nom, o.entreprise) gère cette dualité dans toutes les requêtes.
 */
class OfferRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Compte les offres pour la pagination de la vue pilote/admin */
    public function countOffers(string $search): int
    {
        $sql = "
            SELECT COUNT(*)
            FROM offres o
            LEFT JOIN entreprises e ON e.id = o.entreprise_id
            WHERE 1 = 1
        ";

        $params = [];

        if ($search !== '') {
            $sql .= "
                AND (
                    o.titre LIKE :search_title
                    OR o.lieu LIKE :search_location
                    OR o.entreprise LIKE :search_company
                    OR e.nom LIKE :search_company_name
                )
            ";

            $searchValue = '%' . $search . '%';
            $params['search_title'] = $searchValue;
            $params['search_location'] = $searchValue;
            $params['search_company'] = $searchValue;
            $params['search_company_name'] = $searchValue;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** Retourne une page d'offres pour la vue pilote/admin — triées par date décroissante */
    public function findOffersPaginated(string $search, int $limit, int $offset): array
    {
        $sql = "
            SELECT
                o.id,
                o.titre,
                o.lieu,
                o.remuneration,
                o.duree_semaines,
                o.created_at,
                COALESCE(e.nom, o.entreprise) AS entreprise_nom
            FROM offres o
            LEFT JOIN entreprises e ON e.id = o.entreprise_id
            WHERE 1 = 1
        ";

        $params = [];

        if ($search !== '') {
            $sql .= "
                AND (
                    o.titre LIKE :search_title
                    OR o.lieu LIKE :search_location
                    OR o.entreprise LIKE :search_company
                    OR e.nom LIKE :search_company_name
                )
            ";

            $searchValue = '%' . $search . '%';
            $params['search_title'] = $searchValue;
            $params['search_location'] = $searchValue;
            $params['search_company'] = $searchValue;
            $params['search_company_name'] = $searchValue;
        }

        $sql .= "
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Retourne une liste unifiée de suggestions pour l'autocomplétion de la recherche.
     * Combine via UNION trois sources : titres d'offres, noms d'entreprises, et lieux.
     * UNION (sans ALL) déduplique automatiquement les valeurs identiques.
     */
    public function getOfferSuggestions(): array
    {
        $stmt = $this->pdo->query("
            SELECT suggestion
            FROM (
                SELECT TRIM(o.titre) AS suggestion
                FROM offres o
                WHERE o.titre IS NOT NULL AND TRIM(o.titre) <> ''

                UNION

                SELECT TRIM(COALESCE(e.nom, o.entreprise)) AS suggestion
                FROM offres o
                LEFT JOIN entreprises e ON e.id = o.entreprise_id
                WHERE COALESCE(e.nom, o.entreprise) IS NOT NULL
                  AND TRIM(COALESCE(e.nom, o.entreprise)) <> ''

                UNION

                SELECT TRIM(o.lieu) AS suggestion
                FROM offres o
                WHERE o.lieu IS NOT NULL AND TRIM(o.lieu) <> ''
            ) AS suggestions
            ORDER BY suggestion ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Retourne toutes les entreprises pour alimenter le select du formulaire offre */
    public function getAllCompanies(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, nom
            FROM entreprises
            ORDER BY nom ASC
        ");

        return $stmt->fetchAll();
    }

    /** Retourne toutes les compétences pour les checkboxes du formulaire offre */
    public function getAllSkills(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, nom
            FROM competences
            ORDER BY nom ASC
        ");

        return $stmt->fetchAll();
    }

    /** Retrouve une offre par ID pour le formulaire d'édition pilote */
    public function findOfferById(int $offerId): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT
                o.id,
                o.titre,
                COALESCE(e.nom, o.entreprise) AS entreprise_nom,
                o.lieu,
                o.remuneration,
                o.duree_semaines,
                o.description
            FROM offres o
            LEFT JOIN entreprises e ON e.id = o.entreprise_id
            WHERE o.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $offerId]);

        return $stmt->fetch();
    }

    /** Retourne les IDs des compétences d'une offre — pour pré-cocher les checkboxes en édition */
    public function getOfferSkillIds(int $offerId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT competence_id
            FROM offre_competence
            WHERE offre_id = :offre_id
        ");
        $stmt->execute(['offre_id' => $offerId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Recherche une entreprise par nom exact — pour éviter les doublons lors de la création */
    public function findCompanyByName(string $companyName): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT id, nom
            FROM entreprises
            WHERE nom = :nom
            LIMIT 1
        ");
        $stmt->execute(['nom' => $companyName]);

        return $stmt->fetch();
    }

    /** Retourne tous les IDs de compétences — pour valider que les IDs soumis existent bien en BDD */
    public function getAllSkillIds(): array
    {
        $stmt = $this->pdo->query("SELECT id FROM competences");

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Crée ou met à jour une offre avec ses compétences dans une transaction.
     *
     * Logique des compétences (dans la transaction) :
     * 1. Pour chaque nouvelle compétence saisie en texte libre ($newSkillNames) :
     *    - Recherche insensible à la casse (LOWER) pour éviter les doublons
     *    - Si elle existe déjà → récupère son ID
     *    - Sinon → l'insère et récupère le nouvel ID
     * 2. Fusionne les IDs existants ($competenceIds) et les nouveaux dans un tableau unique
     * 3. Supprime toutes les liaisons offre↔compétence existantes (table offre_competence)
     * 4. Réinsère les liaisons avec la nouvelle liste complète
     *
     * Cette approche "delete+insert" est plus simple qu'un diff pour les relations N-N.
     */
    public function saveOffer(
        ?int $offerId,
        string $titre,
        array $company,
        string $lieu,
        float $remuneration,
        int $dureeSemaines,
        string $description,
        array $competenceIds,
        array $newSkillNames
    ): int {
        $isEdit = $offerId !== null;

        $this->pdo->beginTransaction();

        try {
            if ($isEdit) {
                $stmt = $this->pdo->prepare("
                    UPDATE offres
                    SET
                        titre = :titre,
                        entreprise_id = :entreprise_id,
                        entreprise = :entreprise_nom,
                        lieu = :lieu,
                        remuneration = :remuneration,
                        duree_semaines = :duree_semaines,
                        description = :description
                    WHERE id = :id
                ");
                $stmt->execute([
                    'id' => $offerId,
                    'titre' => $titre,
                    'entreprise_id' => (int) $company['id'],
                    'entreprise_nom' => $company['nom'],
                    'lieu' => $lieu,
                    'remuneration' => $remuneration,
                    'duree_semaines' => $dureeSemaines,
                    'description' => $description,
                ]);

                $currentOfferId = $offerId;
            } else {
                $stmt = $this->pdo->prepare("
                    INSERT INTO offres (
                        titre,
                        entreprise_id,
                        entreprise,
                        lieu,
                        remuneration,
                        duree_semaines,
                        description
                    )
                    VALUES (
                        :titre,
                        :entreprise_id,
                        :entreprise_nom,
                        :lieu,
                        :remuneration,
                        :duree_semaines,
                        :description
                    )
                ");
                $stmt->execute([
                    'titre' => $titre,
                    'entreprise_id' => (int) $company['id'],
                    'entreprise_nom' => $company['nom'],
                    'lieu' => $lieu,
                    'remuneration' => $remuneration,
                    'duree_semaines' => $dureeSemaines,
                    'description' => $description,
                ]);

                $currentOfferId = (int) $this->pdo->lastInsertId();
            }

            $createdOrFoundSkillIds = [];

            foreach ($newSkillNames as $skillName) {
                $findSkillStmt = $this->pdo->prepare("
                    SELECT id
                    FROM competences
                    WHERE LOWER(nom) = LOWER(:nom)
                    LIMIT 1
                ");
                $findSkillStmt->execute(['nom' => $skillName]);
                $existingSkillId = $findSkillStmt->fetchColumn();

                if ($existingSkillId !== false) {
                    $createdOrFoundSkillIds[] = (int) $existingSkillId;
                } else {
                    $insertSkillStmt = $this->pdo->prepare("
                        INSERT INTO competences (nom)
                        VALUES (:nom)
                    ");
                    $insertSkillStmt->execute(['nom' => $skillName]);
                    $createdOrFoundSkillIds[] = (int) $this->pdo->lastInsertId();
                }
            }

            $allSkillIds = array_values(array_unique(array_merge($competenceIds, $createdOrFoundSkillIds)));

            $deleteSkillsStmt = $this->pdo->prepare("
                DELETE FROM offre_competence
                WHERE offre_id = :offre_id
            ");
            $deleteSkillsStmt->execute([
                'offre_id' => $currentOfferId,
            ]);

            $insertSkillLinkStmt = $this->pdo->prepare("
                INSERT INTO offre_competence (offre_id, competence_id)
                VALUES (:offre_id, :competence_id)
            ");

            foreach ($allSkillIds as $competenceId) {
                $insertSkillLinkStmt->execute([
                    'offre_id' => $currentOfferId,
                    'competence_id' => $competenceId,
                ]);
            }

            $this->pdo->commit();

            return $currentOfferId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Supprime une offre et toutes ses dépendances dans une transaction.
     * Ordre : wishlist → candidatures → compétences liées → offre.
     */
    public function deleteOffer(int $offerId): void
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("DELETE FROM student_wishlist WHERE offre_id = :id");
            $stmt->execute(['id' => $offerId]);

            $stmt = $this->pdo->prepare("DELETE FROM candidatures WHERE offre_id = :id");
            $stmt->execute(['id' => $offerId]);

            $stmt = $this->pdo->prepare("DELETE FROM offre_competence WHERE offre_id = :id");
            $stmt->execute(['id' => $offerId]);

            $stmt = $this->pdo->prepare("DELETE FROM offres WHERE id = :id");
            $stmt->execute(['id' => $offerId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /** Compte les offres publiques selon les filtres actifs — pour la pagination de la page /offres */
    public function countPublicOffers(
        string $searchQuery,
        string $skillsQuery,
        string $locationQuery,
        string $durationQuery,
        string $salaryQuery
    ): int {
        [$whereSql, $params] = $this->buildPublicFilters(
            $searchQuery,
            $skillsQuery,
            $locationQuery,
            $durationQuery,
            $salaryQuery
        );

        $sql = "
            SELECT COUNT(DISTINCT o.id)
            FROM offres o
            $whereSql
        ";

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':' . $key, $value);
            }
        }

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Retourne une page d'offres publiques avec leurs compétences et l'ordre de tri choisi.
     *
     * GROUP_CONCAT(DISTINCT c.nom ... SEPARATOR '||') agrège toutes les compétences d'une offre
     * en une seule chaîne séparée par '||'. Après le fetch, la chaîne est éclatée en tableau
     * PHP (skills_concat → offer['skills']). Ce mécanisme évite un problème N+1 queries
     * (une requête par offre pour ses compétences).
     *
     * Le tri est injecté via une variable PHP dans le SQL (pas un paramètre PDO) car
     * ORDER BY ne supporte pas les paramètres bindés — seules des valeurs whitelistées
     * sont acceptées (pas d'injection possible grâce aux conditions if/elseif).
     */
    public function findPublicOffersPaginated(
        string $searchQuery,
        string $skillsQuery,
        string $locationQuery,
        string $durationQuery,
        string $salaryQuery,
        string $sortQuery,
        int $limit,
        int $offset
    ): array {
        [$whereSql, $params] = $this->buildPublicFilters(
            $searchQuery,
            $skillsQuery,
            $locationQuery,
            $durationQuery,
            $salaryQuery
        );

        $orderBy = 'o.created_at DESC';
        if ($sortQuery === 'salary_asc') {
            $orderBy = 'o.remuneration ASC';
        } elseif ($sortQuery === 'salary_desc') {
            $orderBy = 'o.remuneration DESC';
        } elseif ($sortQuery === 'duration_asc') {
            $orderBy = 'o.duree_semaines ASC';
        } elseif ($sortQuery === 'duration_desc') {
            $orderBy = 'o.duree_semaines DESC';
        }

        $sql = "
            SELECT
                o.id,
                o.titre,
                o.entreprise,
                o.lieu,
                o.duree_semaines,
                o.remuneration,
                o.description,
                o.created_at,
                COALESCE(o.entreprise, 'Entreprise non définie') AS entreprise_nom,
                GROUP_CONCAT(DISTINCT c.nom ORDER BY c.nom SEPARATOR '||') AS skills_concat
            FROM offres o
            LEFT JOIN offre_competence oc ON oc.offre_id = o.id
            LEFT JOIN competences c ON c.id = oc.competence_id
            $whereSql
            GROUP BY
                o.id,
                o.titre,
                o.entreprise,
                o.lieu,
                o.duree_semaines,
                o.remuneration,
                o.description,
                o.created_at
            ORDER BY $orderBy
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':' . $key, $value);
            }
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($offers as &$offer) {
            $offer['skills'] = [];

            if (!empty($offer['skills_concat'])) {
                $skillNames = explode('||', (string) $offer['skills_concat']);
                foreach ($skillNames as $skillName) {
                    $skillName = trim($skillName);
                    if ($skillName !== '') {
                        $offer['skills'][] = ['nom' => $skillName];
                    }
                }
            }
        }
        unset($offer);

        return $offers;
    }

    /**
     * Retourne le détail complet d'une offre publique avec les infos de l'entreprise liée.
     * Utilisé pour la page de détail d'une offre (/offres/{id}).
     */
    public function findPublicOfferDetailById(int $offerId): \App\Model\Offer|false
    {
        $stmt = $this->pdo->prepare("
            SELECT
                o.id,
                o.titre,
                o.entreprise,
                o.lieu,
                o.duree_semaines,
                o.remuneration,
                o.description,
                o.created_at,
                o.entreprise_id,
                e.nom AS entreprise_nom,
                e.siret AS entreprise_siret,
                e.secteur AS entreprise_secteur,
                e.ville AS entreprise_ville,
                e.site_web AS entreprise_site_web,
                e.note AS entreprise_note
            FROM offres o
            LEFT JOIN entreprises e ON e.id = o.entreprise_id
            WHERE o.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $offerId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        // Hydratation de l'entité Offer
        $offer = new \App\Model\Offer(
            (int) $row['id'],
            $row['titre'],
            $row['entreprise'],
            $row['lieu'],
            (int) $row['duree_semaines'],
            (float) $row['remuneration'],
            $row['description'],
            $row['created_at']
        );

        $offer->setEntrepriseId($row['entreprise_id'] !== null ? (int) $row['entreprise_id'] : null);
        $offer->setEntrepriseNom($row['entreprise_nom']);
        $offer->setEntrepriseSiret($row['entreprise_siret']);
        $offer->setEntrepriseSecteur($row['entreprise_secteur']);
        $offer->setEntrepriseVille($row['entreprise_ville']);
        $offer->setEntrepriseSiteWeb($row['entreprise_site_web']);
        $offer->setEntrepriseNote($row['entreprise_note'] !== null ? (float) $row['entreprise_note'] : null);

        return $offer;
    }

    /** Retourne les compétences d'une offre (noms) — pour l'affichage sur la page de détail */
    public function findOfferSkillsByOfferId(int $offerId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT c.nom
            FROM offre_competence oc
            INNER JOIN competences c ON c.id = oc.competence_id
            WHERE oc.offre_id = :offre_id
            ORDER BY c.nom ASC
        ");
        $stmt->execute(['offre_id' => $offerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Vérifie si une offre est dans la wishlist d'un étudiant — pour afficher le bon bouton */
    public function isOfferInWishlist(int $userId, int $offerId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM student_wishlist
            WHERE user_id = :user_id
              AND offre_id = :offre_id
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'offre_id' => $offerId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    /** Vérifie si l'étudiant a déjà postulé — pour désactiver le bouton "Postuler" */
    public function hasStudentAppliedToOffer(int $userId, int $offerId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM candidatures
            WHERE student_user_id = :user_id
              AND offre_id = :offre_id
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'offre_id' => $offerId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Construit dynamiquement les conditions WHERE pour les filtres publics des offres.
     *
     * Filtres gérés :
     * - Recherche texte : cherche dans titre, entreprise et description
     * - Localisation : LIKE sur le champ lieu
     * - Durée max : duree_semaines <= valeur (filtre "au plus X semaines")
     * - Salaire min : remuneration >= valeur (filtre "au moins X €")
     * - Compétences : chaque mot clé doit matcher au moins une compétence via EXISTS
     *   (sous-requête corrélée — garantit que l'offre possède TOUTES les compétences cherchées)
     *
     * Retourne un tableau [fragment WHERE SQL, paramètres associatifs] pour être
     * réutilisé à la fois dans countPublicOffers() et findPublicOffersPaginated().
     *
     * @return array{0: string, 1: array} [clause WHERE ou chaîne vide, paramètres]
     */
    private function buildPublicFilters(
        string $searchQuery,
        string $skillsQuery,
        string $locationQuery,
        string $durationQuery,
        string $salaryQuery
    ): array {
        $where = [];
        $params = [];

        if ($searchQuery !== '') {
            $where[] = '(
                o.titre LIKE :search_title
                OR o.entreprise LIKE :search_company
                OR o.description LIKE :search_description
            )';

            $searchValue = '%' . $searchQuery . '%';
            $params['search_title'] = $searchValue;
            $params['search_company'] = $searchValue;
            $params['search_description'] = $searchValue;
        }

        if ($locationQuery !== '') {
            $where[] = 'o.lieu LIKE :location';
            $params['location'] = '%' . $locationQuery . '%';
        }

        if ($durationQuery !== '' && ctype_digit($durationQuery)) {
            $where[] = 'o.duree_semaines <= :duration';
            $params['duration'] = (int) $durationQuery;
        }

        if ($salaryQuery !== '' && is_numeric($salaryQuery)) {
            $where[] = 'o.remuneration >= :salary';
            $params['salary'] = (float) $salaryQuery;
        }

        if ($skillsQuery !== '') {
            $skillWords = array_filter(array_map('trim', explode(',', $skillsQuery)));

            if ($skillWords !== []) {
                $skillConditions = [];

                foreach ($skillWords as $index => $skillWord) {
                    $paramName = 'skill_' . $index;
                    $skillConditions[] = "EXISTS (
                        SELECT 1
                        FROM offre_competence oc_filter
                        INNER JOIN competences c_filter ON c_filter.id = oc_filter.competence_id
                        WHERE oc_filter.offre_id = o.id
                          AND c_filter.nom LIKE :$paramName
                    )";
                    $params[$paramName] = '%' . $skillWord . '%';
                }

                $where[] = '(' . implode(' AND ', $skillConditions) . ')';
            }
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        return [$whereSql, $params];
    }
}