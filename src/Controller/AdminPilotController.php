<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\PilotRepository;
use App\Security\Csrf;
use App\Support\PilotPromotionAccess;
use Twig\Environment;

/**
 * Contrôleur de gestion des pilotes (admin uniquement)
 *
 * Permet à l'administrateur de lister, créer, modifier et supprimer des pilotes.
 * Lors de la création/édition, les promotions assignées au pilote sont gérées
 * via une liste de checkboxes (pilot_promotions N-N).
 */
class AdminPilotController
{
    private Environment $twig;
    private PilotRepository $pilotRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->pilotRepository = new PilotRepository(Database::getConnection());
    }

    /** Liste tous les pilotes avec leurs promotions, filtrables par recherche */
    public function index(): string
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'administrateur') {
            header('Location: /connexion');
            exit;
        }

        $search = trim((string) ($_GET['q'] ?? ''));

        return $this->twig->render('admin-pilots.html.twig', [
            'pilots' => $this->pilotRepository->findAll($search),
            'search' => $search,
        ]);
    }

    /** Affiche le formulaire de création d'un pilote */
    public function create(): string
    {
        return $this->handleForm(null);
    }

    /** Affiche le formulaire d'édition d'un pilote existant */
    public function edit(int $pilotId): string
    {
        return $this->handleForm($pilotId);
    }

    /**
     * Gère le formulaire de création et d'édition d'un pilote (méthode mutualisée).
     *
     * En édition, le mot de passe n'est pas modifiable (null passé au repository).
     * Les promotions soumises sont validées contre la liste des promotions actives
     * pour éviter l'injection d'IDs arbitraires.
     * Les IDs de promotions sont dédupliqués et convertis en entiers.
     */
    private function handleForm(?int $pilotId): string
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'administrateur') {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();
        $isEdit = $pilotId !== null;
        $error = null;
        $success = null;

        $promotions = $this->pilotRepository->getActivePromotions();
        // Extrait les IDs pour valider que les promotions soumises existent bien en BDD
        $availablePromotionIds = array_map(static fn(array $promotion): int => (int) $promotion['id'], $promotions);

        $pilot = [
            'id' => null,
            'nom' => '',
            'prenom' => '',
            'email' => '',
            'promotion_ids' => [],
        ];

        if ($isEdit) {
            $existingPilot = $this->pilotRepository->findById($pilotId);

            if (!$existingPilot) {
                http_response_code(404);
                return 'Pilote introuvable.';
            }

            $pilot = $existingPilot;
            $pilot['promotion_ids'] = PilotPromotionAccess::getAssignedPromotionIds($pdo, (int) $pilotId);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

            $nom = trim((string) ($_POST['nom'] ?? ''));
            $prenom = trim((string) ($_POST['prenom'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $promotionIdsRaw = $_POST['promotion_ids'] ?? [];

            // Les checkboxes HTML envoient un tableau de strings.
            // ctype_digit() filtre les valeurs non numériques pour éviter l'injection d'IDs arbitraires.
            // is_array() protège contre le cas où un seul ID serait envoyé comme chaîne (pas tableau).
            $promotionIds = [];
            if (is_array($promotionIdsRaw)) {
                foreach ($promotionIdsRaw as $promotionIdRaw) {
                    if (ctype_digit((string) $promotionIdRaw)) {
                        $promotionIds[] = (int) $promotionIdRaw;
                    }
                }
            }

            $promotionIds = array_values(array_unique($promotionIds));

            $pilot['nom'] = $nom;
            $pilot['prenom'] = $prenom;
            $pilot['email'] = $email;
            $pilot['promotion_ids'] = $promotionIds;

            if ($nom === '' || $prenom === '' || $email === '') {
                $error = 'Merci de remplir tous les champs obligatoires.';
            } elseif (mb_strlen($nom) > 100 || mb_strlen($prenom) > 100) {
                $error = 'Le nom et le prénom ne doivent pas dépasser 100 caractères.';
            } elseif (mb_strlen($email) > 255) {
                $error = "L'email ne doit pas dépasser 255 caractères.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Adresse email invalide.';
            } elseif (!$isEdit && mb_strlen($password) < 8) {
                $error = 'Le mot de passe initial doit contenir au moins 8 caractères.';
            } elseif ($promotionIds === []) {
                $error = 'Merci de sélectionner au moins une promotion.';
            // Vérifie que toutes les promotions soumises font partie des promotions actives connues
            } elseif (array_diff($promotionIds, $availablePromotionIds) !== []) {
                $error = 'Une ou plusieurs promotions sélectionnées sont invalides.';
            } elseif ($this->pilotRepository->emailExists($email, $isEdit ? $pilotId : null)) {
                $error = 'Cet email est déjà utilisé.';
            } else {
                try {
                    // En édition : null pour le mot de passe → le repository ne le modifie pas.
                    // En création : le mot de passe est passé en clair, le repository le hashe avec bcrypt.
                    $finalPilotId = $this->pilotRepository->savePilot(
                        $isEdit ? $pilotId : null,
                        $nom,
                        $prenom,
                        $email,
                        $isEdit ? null : $password,
                        $promotionIds
                    );

                    if ($isEdit) {
                        $success = 'Pilote modifié avec succès.';
                    } else {
                        $success = 'Pilote créé avec succès.';
                        $pilotId = $finalPilotId;
                        $isEdit = true;
                    }

                    $reloadedPilot = $this->pilotRepository->findById($finalPilotId);
                    if ($reloadedPilot) {
                        $pilot = $reloadedPilot;
                        $pilot['promotion_ids'] = PilotPromotionAccess::getAssignedPromotionIds($pdo, $finalPilotId);
                    }
                } catch (\Throwable $e) {
                    $error = $isEdit
                        ? 'Erreur lors de la modification du pilote.'
                        : 'Erreur lors de la création du pilote.';
                }
            }
        }

        return $this->twig->render('admin-pilot-form.html.twig', [
            'pilot' => $pilot,
            'promotions' => $promotions,
            'is_edit' => $isEdit,
            'error' => $error,
            'success' => $success,
        ]);
    }

    /**
     * Supprime un pilote.
     * Les liaisons pilot_promotions sont supprimées automatiquement (ON DELETE CASCADE en BDD).
     */
    public function delete(int $pilotId): void
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'administrateur') {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $this->pilotRepository->delete($pilotId);

        header('Location: /admin-pilotes');
        exit;
    }
}