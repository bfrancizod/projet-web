<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Repository des entreprises.
 * Centralise les requêtes SQL liées à la table entreprises.
 */
class CompanyRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function countAll(string $search = ''): int
    {
        $sql = "
            SELECT COUNT(*)
            FROM entreprises
            WHERE 1 = 1
        ";

        $params = $this->buildSearchParams($search);

        if ($search !== '') {
            $sql .= $this->getSearchCondition();
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Retourne les entreprises filtrées avec pagination.
     */
    public function findPaginated(string $search, int $limit, int $offset): array
    {
        $sql = "
            SELECT
                id,
                nom,
                siret,
                secteur,
                ville,
                site_web,
                note,
                created_at
            FROM entreprises
            WHERE 1 = 1
        ";

        $params = $this->buildSearchParams($search);

        if ($search !== '') {
            $sql .= $this->getSearchCondition();
        }

        $sql .= "
            ORDER BY nom ASC
            LIMIT :limit_value OFFSET :offset_value
        ";

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }

        $stmt->bindValue(':limit_value', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset_value', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findAll(string $search): array
    {
        return $this->findPaginated($search, 10, 0);
    }

    public function findById(int $companyId): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT
                id,
                nom,
                siret,
                secteur,
                ville,
                site_web,
                note
            FROM entreprises
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            'id' => $companyId,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Vérifie l'unicité du nom d'entreprise.
     * En édition, l'entreprise courante est exclue du contrôle.
     */
    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->pdo->prepare("
                SELECT id
                FROM entreprises
                WHERE nom = :nom
                  AND id != :id
                LIMIT 1
            ");

            $stmt->execute([
                'nom' => $name,
                'id' => $excludeId,
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT id
                FROM entreprises
                WHERE nom = :nom
                LIMIT 1
            ");

            $stmt->execute([
                'nom' => $name,
            ]);
        }

        return (bool) $stmt->fetch();
    }

    public function create(
        string $nom,
        ?string $siret,
        ?string $secteur,
        ?string $ville,
        ?string $siteWeb,
        ?int $note
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO entreprises (nom, siret, secteur, ville, site_web, note)
            VALUES (:nom, :siret, :secteur, :ville, :site_web, :note)
        ");

        $stmt->execute([
            'nom' => $nom,
            'siret' => $siret,
            'secteur' => $secteur,
            'ville' => $ville,
            'site_web' => $siteWeb,
            'note' => $note,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $companyId,
        string $nom,
        ?string $siret,
        ?string $secteur,
        ?string $ville,
        ?string $siteWeb,
        ?int $note
    ): void {
        $stmt = $this->pdo->prepare("
            UPDATE entreprises
            SET nom = :nom,
                siret = :siret,
                secteur = :secteur,
                ville = :ville,
                site_web = :site_web,
                note = :note
            WHERE id = :id
        ");

        $stmt->execute([
            'id' => $companyId,
            'nom' => $nom,
            'siret' => $siret,
            'secteur' => $secteur,
            'ville' => $ville,
            'site_web' => $siteWeb,
            'note' => $note,
        ]);
    }

    /**
     * Supprime une entreprise et ses données liées dans une transaction.
     * L'objectif est d'éviter des données orphelines si une suppression échoue.
     */
    public function delete(int $companyId): void
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                SELECT nom
                FROM entreprises
                WHERE id = :id
                LIMIT 1
            ");

            $stmt->execute([
                'id' => $companyId,
            ]);

            $company = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$company) {
                throw new \RuntimeException('Entreprise introuvable.');
            }

            $companyName = $company['nom'];

            $stmt = $this->pdo->prepare("
                SELECT id
                FROM offres
                WHERE entreprise_id = :id
                   OR entreprise = :nom
            ");

            $stmt->execute([
                'id' => $companyId,
                'nom' => $companyName,
            ]);

            $offers = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if ($offers !== []) {
                $placeholders = [];
                $params = [];

                foreach ($offers as $i => $offerId) {
                    $key = 'id' . $i;
                    $placeholders[] = ':' . $key;
                    $params[$key] = (int) $offerId;
                }

                $inClause = implode(',', $placeholders);

                // Suppression des dépendances des offres avant suppression de l'entreprise
                $stmt = $this->pdo->prepare("DELETE FROM student_wishlist WHERE offre_id IN ($inClause)");
                $stmt->execute($params);

                $stmt = $this->pdo->prepare("DELETE FROM candidatures WHERE offre_id IN ($inClause)");
                $stmt->execute($params);

                $stmt = $this->pdo->prepare("DELETE FROM offre_competence WHERE offre_id IN ($inClause)");
                $stmt->execute($params);
            }

            $stmt = $this->pdo->prepare("
                DELETE FROM offres
                WHERE entreprise_id = :id
                   OR entreprise = :nom
            ");

            $stmt->execute([
                'id' => $companyId,
                'nom' => $companyName,
            ]);

            $stmt = $this->pdo->prepare("
                DELETE FROM entreprise_commentaires
                WHERE entreprise_id = :id
            ");

            $stmt->execute([
                'id' => $companyId,
            ]);

            $stmt = $this->pdo->prepare("
                DELETE FROM entreprises
                WHERE id = :id
            ");

            $stmt->execute([
                'id' => $companyId,
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
     * Condition SQL commune pour la recherche entreprise.
     */
    private function getSearchCondition(): string
    {
        return "
            AND (
                nom LIKE :search_nom
                OR siret LIKE :search_siret
                OR secteur LIKE :search_secteur
                OR ville LIKE :search_ville
            )
        ";
    }

    /**
     * Prépare les paramètres de recherche utilisés par les requêtes préparées PDO.
     */
    private function buildSearchParams(string $search): array
    {
        if ($search === '') {
            return [];
        }

        $searchValue = '%' . $search . '%';

        return [
            'search_nom' => $searchValue,
            'search_siret' => $searchValue,
            'search_secteur' => $searchValue,
            'search_ville' => $searchValue,
        ];
    }
}