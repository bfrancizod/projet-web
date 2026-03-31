<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;
use Twig\Environment;

class AdminCompanyController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(): string
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['administrateur', 'pilote'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();

        $search = trim((string) ($_GET['q'] ?? ''));

        $sql = "
            SELECT
                id,
                nom,
                siret,
                secteur,
                ville,
                site_web,
                note,
                commentaire,
                created_at
            FROM entreprises
            WHERE 1 = 1
        ";

        $params = [];

        if ($search !== '') {
            $sql .= " AND (
                nom LIKE :search_nom
                OR siret LIKE :search_siret
                OR secteur LIKE :search_secteur
                OR ville LIKE :search_ville
            ) ";

            $searchValue = '%' . $search . '%';
            $params['search_nom'] = $searchValue;
            $params['search_siret'] = $searchValue;
            $params['search_secteur'] = $searchValue;
            $params['search_ville'] = $searchValue;
        }

        $sql .= " ORDER BY nom ASC ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $companies = $stmt->fetchAll();

        return $this->twig->render('admin-companies.html.twig', [
            'companies' => $companies,
            'search' => $search,
        ]);
    }

    public function create(): string
    {
        return $this->handleForm(null);
    }

    public function edit(int $companyId): string
    {
        return $this->handleForm($companyId);
    }

    private function handleForm(?int $companyId): string
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['administrateur', 'pilote'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();
        $isEdit = $companyId !== null;
        $error = null;
        $success = null;

        $company = [
            'id' => null,
            'nom' => '',
            'secteur' => '',
            'ville' => '',
            'site_web' => '',
            'note' => '',
            'commentaire' => '',
        ];

        if ($isEdit) {
            $stmt = $pdo->prepare("
                SELECT id, nom, secteur, ville, site_web, note, commentaire
                FROM entreprises
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $companyId]);
            $existingCompany = $stmt->fetch();

            if (!$existingCompany) {
                http_response_code(404);
                return 'Entreprise introuvable.';
            }

            $company = $existingCompany;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

            $nom = trim((string) ($_POST['nom'] ?? ''));
            $secteur = trim((string) ($_POST['secteur'] ?? ''));
            $ville = trim((string) ($_POST['ville'] ?? ''));
            $siteWeb = trim((string) ($_POST['site_web'] ?? ''));
            $noteRaw = trim((string) ($_POST['note'] ?? ''));
            $commentaire = trim((string) ($_POST['commentaire'] ?? ''));

            $note = null;
            if ($noteRaw !== '') {
                $noteInt = (int) $noteRaw;
                if (!is_numeric($noteRaw) || $noteInt < 1 || $noteInt > 5) {
                    $error = 'La note doit être un nombre entier entre 1 et 5.';
                } else {
                    $note = $noteInt;
                }
            }

            $company['nom'] = $nom;
            $company['secteur'] = $secteur;
            $company['ville'] = $ville;
            $company['site_web'] = $siteWeb;
            $company['note'] = $noteRaw;
            $company['commentaire'] = $commentaire;

            if ($error === null && $nom === '') {
                $error = "Le nom de l'entreprise est obligatoire.";
            }

            if ($error === null && $siteWeb !== '' && !filter_var($siteWeb, FILTER_VALIDATE_URL)) {
                $error = "L'URL du site web est invalide.";
            }

            if ($error === null) {
                if ($isEdit) {
                    $stmt = $pdo->prepare("
                        SELECT id FROM entreprises
                        WHERE nom = :nom AND id != :id
                        LIMIT 1
                    ");
                    $stmt->execute(['nom' => $nom, 'id' => $companyId]);
                } else {
                    $stmt = $pdo->prepare("
                        SELECT id FROM entreprises
                        WHERE nom = :nom
                        LIMIT 1
                    ");
                    $stmt->execute(['nom' => $nom]);
                }

                if ($stmt->fetch()) {
                    $error = 'Une entreprise avec ce nom existe déjà.';
                } else {
                    try {
                        if ($isEdit) {
                            $stmt = $pdo->prepare("
                                UPDATE entreprises
                                SET nom = :nom,
                                    secteur = :secteur,
                                    ville = :ville,
                                    site_web = :site_web,
                                    note = :note,
                                    commentaire = :commentaire
                                WHERE id = :id
                            ");
                            $stmt->execute([
                                'id' => $companyId,
                                'nom' => $nom,
                                'secteur' => $secteur !== '' ? $secteur : null,
                                'ville' => $ville !== '' ? $ville : null,
                                'site_web' => $siteWeb !== '' ? $siteWeb : null,
                                'note' => $note,
                                'commentaire' => $commentaire !== '' ? $commentaire : null,
                            ]);

                            $success = 'Entreprise mise à jour avec succès.';
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO entreprises (nom, secteur, ville, site_web, note, commentaire)
                                VALUES (:nom, :secteur, :ville, :site_web, :note, :commentaire)
                            ");
                            $stmt->execute([
                                'nom' => $nom,
                                'secteur' => $secteur !== '' ? $secteur : null,
                                'ville' => $ville !== '' ? $ville : null,
                                'site_web' => $siteWeb !== '' ? $siteWeb : null,
                                'note' => $note,
                                'commentaire' => $commentaire !== '' ? $commentaire : null,
                            ]);

                            $companyId = (int) $pdo->lastInsertId();
                            $isEdit = true;
                            $success = 'Entreprise créée avec succès.';
                        }

                        $stmt = $pdo->prepare("
                            SELECT id, nom, secteur, ville, site_web, note, commentaire
                            FROM entreprises
                            WHERE id = :id
                            LIMIT 1
                        ");
                        $stmt->execute(['id' => $companyId]);
                        $company = $stmt->fetch();
                    } catch (\Throwable $e) {
                        $error = $isEdit
                            ? "Erreur lors de la mise à jour de l'entreprise."
                            : "Erreur lors de la création de l'entreprise.";
                    }
                }
            }
        }

        return $this->twig->render('admin-company-form.html.twig', [
            'company' => $company,
            'is_edit' => $isEdit,
            'error' => $error,
            'success' => $success,
        ]);
    }

    public function delete(int $companyId): void
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['administrateur', 'pilote'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE offres
                SET entreprise_id = NULL
                WHERE entreprise_id = :id
            ");
            $stmt->execute(['id' => $companyId]);

            $stmt = $pdo->prepare("
                DELETE FROM entreprises
                WHERE id = :id
            ");
            $stmt->execute(['id' => $companyId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        header('Location: /admin-entreprises');
        exit;
    }
}