<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;
use Twig\Environment;
use PDO;

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

        $skillsStmt = $pdo->query("
            SELECT id, nom
            FROM competences
            ORDER BY nom ASC
        ");
        $skills = $skillsStmt->fetchAll();

        $offer = [
            'id' => null,
            'titre' => '',
            'entreprise_nom' => '',
            'lieu' => '',
            'remuneration' => '',
            'duree_semaines' => '',
            'description' => '',
            'competence_ids' => [],
            'new_skill_names' => [],
        ];

        if ($isEdit) {
            $stmt = $pdo->prepare("
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
            $existingOffer = $stmt->fetch();

            if (!$existingOffer) {
                http_response_code(404);
                return 'Offre introuvable.';
            }

            $offer = array_merge($offer, $existingOffer);

            $skillLinkStmt = $pdo->prepare("
                SELECT competence_id
                FROM offre_competence
                WHERE offre_id = :offre_id
            ");
            $skillLinkStmt->execute(['offre_id' => $offerId]);
            $offer['competence_ids'] = array_map(
                'intval',
                $skillLinkStmt->fetchAll(PDO::FETCH_COLUMN)
            );
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

            $titre = trim((string) ($_POST['titre'] ?? ''));
            $entrepriseNom = trim((string) ($_POST['entreprise_nom'] ?? ''));
            $lieu = trim((string) ($_POST['lieu'] ?? ''));
            $remunerationRaw = trim((string) ($_POST['remuneration'] ?? ''));
            $dureeRaw = trim((string) ($_POST['duree_semaines'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));

            $competenceIdsRaw = $_POST['competence_ids'] ?? [];
            $newSkillNamesRaw = $_POST['new_skill_names'] ?? [];

            $remuneration = is_numeric($remunerationRaw) ? (float) $remunerationRaw : null;
            $dureeSemaines = ctype_digit($dureeRaw) ? (int) $dureeRaw : null;

            $competenceIds = [];
            if (is_array($competenceIdsRaw)) {
                foreach ($competenceIdsRaw as $competenceIdRaw) {
                    if (ctype_digit((string) $competenceIdRaw)) {
                        $competenceIds[] = (int) $competenceIdRaw;
                    }
                }
            }
            $competenceIds = array_values(array_unique($competenceIds));

            $newSkillNames = [];
            if (is_array($newSkillNamesRaw)) {
                foreach ($newSkillNamesRaw as $skillNamesGroup) {
                    $parts = array_map('trim', explode(',', (string) $skillNamesGroup));
                    foreach ($parts as $skillName) {
                        if ($skillName !== '') {
                            $newSkillNames[] = $skillName;
                        }
                    }
                }
            }
            $newSkillNames = array_values(array_unique($newSkillNames));

            $offer['titre'] = $titre;
            $offer['entreprise_nom'] = $entrepriseNom;
            $offer['lieu'] = $lieu;
            $offer['remuneration'] = $remunerationRaw;
            $offer['duree_semaines'] = $dureeRaw;
            $offer['description'] = $description;
            $offer['competence_ids'] = $competenceIds;
            $offer['new_skill_names'] = $newSkillNames;

            if ($titre === '' || $lieu === '' || $description === '') {
                $error = 'Merci de remplir tous les champs obligatoires.';
            } elseif ($entrepriseNom === '') {
                $error = 'Merci de choisir une entreprise.';
            } elseif ($remuneration === null || $remuneration < 0) {
                $error = 'La rémunération est invalide.';
            } elseif ($dureeSemaines === null || $dureeSemaines <= 0) {
                $error = 'La durée est invalide.';
            } elseif ($competenceIds === [] && $newSkillNames === []) {
                $error = 'Merci d’ajouter au moins une compétence.';
            } else {
                $checkCompany = $pdo->prepare("
                    SELECT id, nom
                    FROM entreprises
                    WHERE nom = :nom
                    LIMIT 1
                ");
                $checkCompany->execute(['nom' => $entrepriseNom]);
                $company = $checkCompany->fetch();

                if (!$company) {
                    $error = 'Entreprise invalide. Merci de choisir une entreprise existante.';
                } else {
                    $validSkillsStmt = $pdo->query("SELECT id FROM competences");
                    $validSkillIds = array_map('intval', $validSkillsStmt->fetchAll(PDO::FETCH_COLUMN));

                    foreach ($competenceIds as $competenceId) {
                        if (!in_array($competenceId, $validSkillIds, true)) {
                            $error = 'Une compétence sélectionnée est invalide.';
                            break;
                        }
                    }

                    if ($error === null) {
                        try {
                            $pdo->beginTransaction();

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
                                    'entreprise_id' => (int) $company['id'],
                                    'entreprise_nom' => $company['nom'],
                                    'lieu' => $lieu,
                                    'remuneration' => $remuneration,
                                    'duree_semaines' => $dureeSemaines,
                                    'description' => $description,
                                ]);

                                $currentOfferId = (int) $offerId;
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
                                    'entreprise_id' => (int) $company['id'],
                                    'entreprise_nom' => $company['nom'],
                                    'lieu' => $lieu,
                                    'remuneration' => $remuneration,
                                    'duree_semaines' => $dureeSemaines,
                                    'description' => $description,
                                ]);

                                $currentOfferId = (int) $pdo->lastInsertId();
                                $offerId = $currentOfferId;
                                $isEdit = true;
                                $success = 'Offre créée avec succès.';
                            }

                            $createdOrFoundSkillIds = [];

                            foreach ($newSkillNames as $skillName) {
                                $findSkillStmt = $pdo->prepare("
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
                                    $insertSkillStmt = $pdo->prepare("
                                        INSERT INTO competences (nom)
                                        VALUES (:nom)
                                    ");
                                    $insertSkillStmt->execute(['nom' => $skillName]);
                                    $createdOrFoundSkillIds[] = (int) $pdo->lastInsertId();
                                }
                            }

                            $allSkillIds = array_values(array_unique(array_merge($competenceIds, $createdOrFoundSkillIds)));

                            $deleteSkillsStmt = $pdo->prepare("
                                DELETE FROM offre_competence
                                WHERE offre_id = :offre_id
                            ");
                            $deleteSkillsStmt->execute([
                                'offre_id' => $currentOfferId,
                            ]);

                            $insertSkillLinkStmt = $pdo->prepare("
                                INSERT INTO offre_competence (offre_id, competence_id)
                                VALUES (:offre_id, :competence_id)
                            ");

                            foreach ($allSkillIds as $competenceId) {
                                $insertSkillLinkStmt->execute([
                                    'offre_id' => $currentOfferId,
                                    'competence_id' => $competenceId,
                                ]);
                            }

                            $pdo->commit();

                            $stmt = $pdo->prepare("
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
                            $stmt->execute(['id' => $currentOfferId]);
                            $reloadedOffer = $stmt->fetch();

                            if ($reloadedOffer) {
                                $offer = array_merge($offer, $reloadedOffer);
                            }

                            $skillLinkStmt = $pdo->prepare("
                                SELECT competence_id
                                FROM offre_competence
                                WHERE offre_id = :offre_id
                            ");
                            $skillLinkStmt->execute(['offre_id' => $currentOfferId]);
                            $offer['competence_ids'] = array_map(
                                'intval',
                                $skillLinkStmt->fetchAll(PDO::FETCH_COLUMN)
                            );
                            $offer['new_skill_names'] = [];

                            $skillsStmt = $pdo->query("
                                SELECT id, nom
                                FROM competences
                                ORDER BY nom ASC
                            ");
                            $skills = $skillsStmt->fetchAll();
                        } catch (\Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }

                            $error = $isEdit
                                ? 'Erreur lors de la mise à jour de l’offre.'
                                : 'Erreur lors de la création de l’offre.';
                        }
                    }
                }
            }
        }

        return $this->twig->render('pilot-offer-form.html.twig', [
            'offer' => $offer,
            'companies' => $companies,
            'skills' => $skills,
            'is_edit' => $isEdit,
            'error' => $error,
            'success' => $success,
            'csrf_token' => Csrf::token(),
        ]);
    }
}