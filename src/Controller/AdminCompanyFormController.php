<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;
use Twig\Environment;

class AdminCompanyFormController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
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
        // Seuls les administrateurs et les pilotes peuvent acceder a cette page
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

        // Valeurs par defaut du formulaire
        $company = [
            'id'          => null,
            'nom'         => '',
            'secteur'     => '',
            'ville'       => '',
            'site_web'    => '',
            'note'        => '',
            'commentaire' => '',
        ];

        // Si on est en mode edition, on charge les donnees existantes
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

        // Traitement du formulaire quand il est soumis
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

            $nom         = trim((string) ($_POST['nom']         ?? ''));
            $secteur     = trim((string) ($_POST['secteur']     ?? ''));
            $ville       = trim((string) ($_POST['ville']       ?? ''));
            $siteWeb     = trim((string) ($_POST['site_web']    ?? ''));
            $noteRaw     = trim((string) ($_POST['note']        ?? ''));
            $commentaire = trim((string) ($_POST['commentaire'] ?? ''));

            // --- Validation de la note ---
            // La note vient du systeme d'etoiles : c'est soit vide (pas de note),
            // soit un entier entre 1 et 5.
            $note = null;
            if ($noteRaw !== '') {
                $noteInt = (int) $noteRaw;
                if (!is_numeric($noteRaw) || $noteInt < 1 || $noteInt > 5) {
                    $error = 'La note doit etre un nombre entier entre 1 et 5.';
                } else {
                    $note = $noteInt;
                }
            }

            // On met a jour les valeurs du formulaire pour le re-affichage
            $company['nom']         = $nom;
            $company['secteur']     = $secteur;
            $company['ville']       = $ville;
            $company['site_web']    = $siteWeb;
            $company['note']        = $noteRaw;
            $company['commentaire'] = $commentaire;

            // --- Validation du nom (obligatoire) ---
            if ($error === null && $nom === '') {
                $error = "Le nom de l'entreprise est obligatoire.";
            }

            // --- Validation de l'URL ---
            if ($error === null && $siteWeb !== '' && !filter_var($siteWeb, FILTER_VALIDATE_URL)) {
                $error = "L'URL du site web est invalide.";
            }

            // --- Sauvegarde en base de donnees ---
            if ($error === null) {
                // On verifie qu'une autre entreprise n'a pas le meme nom
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
                    $error = 'Une entreprise avec ce nom existe deja.';
                } else {
                    try {
                        if ($isEdit) {
                            // Mise a jour de l'entreprise existante
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
                                'id'          => $companyId,
                                'nom'         => $nom,
                                'secteur'     => $secteur     !== '' ? $secteur     : null,
                                'ville'       => $ville       !== '' ? $ville       : null,
                                'site_web'    => $siteWeb     !== '' ? $siteWeb     : null,
                                'note'        => $note,
                                'commentaire' => $commentaire !== '' ? $commentaire : null,
                            ]);

                            $success = 'Entreprise mise a jour avec succes.';
                        } else {
                            // Creation d'une nouvelle entreprise
                            $stmt = $pdo->prepare("
                                INSERT INTO entreprises (nom, secteur, ville, site_web, note, commentaire)
                                VALUES (:nom, :secteur, :ville, :site_web, :note, :commentaire)
                            ");
                            $stmt->execute([
                                'nom'         => $nom,
                                'secteur'     => $secteur     !== '' ? $secteur     : null,
                                'ville'       => $ville       !== '' ? $ville       : null,
                                'site_web'    => $siteWeb     !== '' ? $siteWeb     : null,
                                'note'        => $note,
                                'commentaire' => $commentaire !== '' ? $commentaire : null,
                            ]);

                            $companyId = (int) $pdo->lastInsertId();
                            $isEdit = true;
                            $success = 'Entreprise creee avec succes.';
                        }

                        // On recharge les donnees fraichement sauvegardees
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
                            ? "Erreur lors de la mise a jour de l'entreprise."
                            : "Erreur lors de la creation de l'entreprise.";
                    }
                }
            }
        }

        return $this->twig->render('admin-company-form.html.twig', [
            'company' => $company,
            'is_edit' => $isEdit,
            'error'   => $error,
            'success' => $success,
        ]);
    }
}
