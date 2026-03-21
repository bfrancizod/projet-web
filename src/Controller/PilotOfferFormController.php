<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;
use Twig\Environment;

class PilotOfferFormController
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

    public function edit(int $offerId): string
    {
        return $this->handleForm($offerId);
    }

    private function handleForm(?int $offerId): string
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();
        $isEdit = $offerId !== null;
        $error = null;
        $success = null;

        $companiesStmt = $pdo->query("
            SELECT id, nom
            FROM entreprises
            ORDER BY nom ASC
        ");
        $companies = $companiesStmt->fetchAll();

        $offer = [
            'id' => null,
            'titre' => '',
            'entreprise_id' => '',
            'lieu' => '',
            'remuneration' => '',
            'duree_semaines' => '',
            'description' => '',
        ];

        if ($isEdit) {
            $stmt = $pdo->prepare("
                SELECT
                    id,
                    titre,
                    entreprise_id,
                    lieu,
                    remuneration,
                    duree_semaines,
                    description
                FROM offres
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $offerId]);
            $existingOffer = $stmt->fetch();

            if (!$existingOffer) {
                http_response_code(404);
                return 'Offre introuvable.';
            }

            $offer = $existingOffer;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

            $titre = trim((string) ($_POST['titre'] ?? ''));
            $entrepriseIdRaw = trim((string) ($_POST['entreprise_id'] ?? ''));
            $lieu = trim((string) ($_POST['lieu'] ?? ''));
            $remunerationRaw = trim((string) ($_POST['remuneration'] ?? ''));
            $dureeRaw = trim((string) ($_POST['duree_semaines'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));

            $entrepriseId = ctype_digit($entrepriseIdRaw) ? (int) $entrepriseIdRaw : null;
            $remuneration = is_numeric($remunerationRaw) ? (float) $remunerationRaw : null;
            $dureeSemaines = ctype_digit($dureeRaw) ? (int) $dureeRaw : null;

            $offer['titre'] = $titre;
            $offer['entreprise_id'] = $entrepriseIdRaw;
            $offer['lieu'] = $lieu;
            $offer['remuneration'] = $remunerationRaw;
            $offer['duree_semaines'] = $dureeRaw;
            $offer['description'] = $description;

            if ($titre === '' || $lieu === '' || $description === '') {
                $error = 'Merci de remplir tous les champs obligatoires.';
            } elseif ($entrepriseId === null) {
                $error = 'Merci de choisir une entreprise.';
            } elseif ($remuneration === null || $remuneration < 0) {
                $error = 'La rémunération est invalide.';
            } elseif ($dureeSemaines === null || $dureeSemaines <= 0) {
                $error = 'La durée est invalide.';
            } else {
                $checkCompany = $pdo->prepare("
                    SELECT id, nom
                    FROM entreprises
                    WHERE id = :id
                    LIMIT 1
                ");
                $checkCompany->execute(['id' => $entrepriseId]);
                $company = $checkCompany->fetch();

                if (!$company) {
                    $error = 'Entreprise invalide.';
                } else {
                    try {
                        if ($isEdit) {
                            $stmt = $pdo->prepare("
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
                                'entreprise_id' => $entrepriseId,
                                'entreprise_nom' => $company['nom'],
                                'lieu' => $lieu,
                                'remuneration' => $remuneration,
                                'duree_semaines' => $dureeSemaines,
                                'description' => $description,
                            ]);

                            $success = 'Offre mise à jour avec succès.';
                        } else {
                            $stmt = $pdo->prepare("
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
                                'entreprise_id' => $entrepriseId,
                                'entreprise_nom' => $company['nom'],
                                'lieu' => $lieu,
                                'remuneration' => $remuneration,
                                'duree_semaines' => $dureeSemaines,
                                'description' => $description,
                            ]);

                            $offerId = (int) $pdo->lastInsertId();
                            $isEdit = true;
                            $success = 'Offre créée avec succès.';
                        }

                        $stmt = $pdo->prepare("
                            SELECT
                                id,
                                titre,
                                entreprise_id,
                                lieu,
                                remuneration,
                                duree_semaines,
                                description
                            FROM offres
                            WHERE id = :id
                            LIMIT 1
                        ");
                        $stmt->execute(['id' => $offerId]);
                        $offer = $stmt->fetch();
                    } catch (\Throwable $e) {
                        $error = $isEdit
                            ? 'Erreur lors de la mise à jour de l’offre.'
                            : 'Erreur lors de la création de l’offre.';
                    }
                }
            }
        }

        return $this->twig->render('pilot-offer-form.html.twig', [
            'offer' => $offer,
            'companies' => $companies,
            'is_edit' => $isEdit,
            'error' => $error,
            'success' => $success,
        ]);
    }
}