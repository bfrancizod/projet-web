<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

class CompanyRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findAll(string $search): array
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

        $params = [];

        if ($search !== '') {
            $sql .= "
                AND (
                    nom LIKE :search_nom
                    OR siret LIKE :search_siret
                    OR secteur LIKE :search_secteur
                    OR ville LIKE :search_ville
                )
            ";

            $searchValue = '%' . $search . '%';
            $params['search_nom'] = $searchValue;
            $params['search_siret'] = $searchValue;
            $params['search_secteur'] = $searchValue;
            $params['search_ville'] = $searchValue;
        }

        $sql .= " ORDER BY nom ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        $stmt->execute(['id' => $companyId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

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

    public function delete(int $companyId): void
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                UPDATE offres
                SET entreprise_id = NULL
                WHERE entreprise_id = :id
            ");
            $stmt->execute(['id' => $companyId]);

            $stmt = $this->pdo->prepare("
                DELETE FROM entreprise_commentaires
                WHERE entreprise_id = :id
            ");
            $stmt->execute(['id' => $companyId]);

            $stmt = $this->pdo->prepare("
                DELETE FROM entreprises
                WHERE id = :id
            ");
            $stmt->execute(['id' => $companyId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}