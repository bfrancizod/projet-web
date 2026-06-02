<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\OfferRepository;
use App\Security\Csrf;
use Twig\Environment;

/**
 * Contrôleur de gestion des offres de stage (pilote et admin)
 *
 * Permet aux pilotes et admins de lister, créer, modifier et supprimer des offres.
 * La pagination affiche PER_PAGE offres par page.
 */
class PilotOfferController
{
    private Environment $twig;
    private OfferRepository $offerRepository;

    /** Nombre d'offres affichées par page dans la liste */
    private const PER_PAGE = 10;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->offerRepository = new OfferRepository(Database::getConnection());
    }

    /**
     * Liste paginée des offres avec recherche.
     * Si la page demandée dépasse le total, on redirige vers la dernière page.
     */
    public function index(): string
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        $search = trim((string) ($_GET['q'] ?? ''));

        $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
        $page = ($page !== false && $page !== null && $page > 0) ? $page : 1;

        $totalOffers = $this->offerRepository->countOffers($search);
        $totalPages = max(1, (int) ceil($totalOffers / self::PER_PAGE));

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * self::PER_PAGE;

        $offers = $this->offerRepository->findOffersPaginated(
            $search,
            self::PER_PAGE,
            $offset
        );

        $offerSuggestions = $this->offerRepository->getOfferSuggestions();

        return $this->twig->render('pilot-offers.html.twig', [
            'offers' => $offers,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalOffers' => $totalOffers,
            'search' => $search,
            'offerTitles' => $offerSuggestions,
        ]);
    }

    /** Affiche le formulaire de création d'une nouvelle offre */
    public function create(): string
    {
        return $this->handleForm(null);
    }

    /** Affiche le formulaire d'édition d'une offre existante */
    public function edit(int $offerId): string
    {
        return $this->handleForm($offerId);
    }

    /**
     * Gère le formulaire de création et d'édition d'une offre (méthode mutualisée).
     *
     * Gestion des compétences (deux sources) :
     * - $competenceIds : IDs de compétences existantes cochées (checkboxes)
     * - $newSkillNames : noms saisis librement (champ texte, séparés par virgule)
     * Les nouvelles compétences sont créées si inexistantes, puis fusionnées avec les existantes.
     *
     * L'entreprise est validée par nom exact — elle doit exister dans la table entreprises.
     * Les IDs soumis sont validés contre la BDD pour éviter l'injection d'IDs arbitraires.
     */
    private function handleForm(?int $offerId): string
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        $isEdit = $offerId !== null;
        $error = null;
        $success = null;

        $companies = $this->offerRepository->getAllCompanies();
        $skills = $this->offerRepository->getAllSkills();

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
            $existingOffer = $this->offerRepository->findOfferById($offerId);

            if (!$existingOffer) {
                http_response_code(404);
                return 'Offre introuvable.';
            }

            $offer = array_merge($offer, $existingOffer);
            $offer['competence_ids'] = $this->offerRepository->getOfferSkillIds($offerId);
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

            // Filtre et convertit les IDs de compétences en entiers valides (protection injection)
            $competenceIds = [];
            if (is_array($competenceIdsRaw)) {
                foreach ($competenceIdsRaw as $competenceIdRaw) {
                    if (ctype_digit((string) $competenceIdRaw)) {
                        $competenceIds[] = (int) $competenceIdRaw;
                    }
                }
            }
            $competenceIds = array_values(array_unique($competenceIds));

            // Découpe les nouvelles compétences saisies librement (séparées par virgule)
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
            // Une offre doit avoir au moins une compétence, peu importe la source
            // (existante cochée OU nouvelle saisie — les deux listes peuvent être combinées)
            } elseif ($competenceIds === [] && $newSkillNames === []) {
                $error = ‘Merci d"ajouter au moins une compétence.’;
            } else {
                $company = $this->offerRepository->findCompanyByName($entrepriseNom);

                if (!$company) {
                    $error = 'Entreprise invalide. Merci de choisir une entreprise existante.';
                } else {
                    // Vérifie que les IDs soumis correspondent à des compétences réelles en BDD
                    $validSkillIds = $this->offerRepository->getAllSkillIds();

                    foreach ($competenceIds as $competenceId) {
                        if (!in_array($competenceId, $validSkillIds, true)) {
                            $error = 'Une compétence sélectionnée est invalide.';
                            break;
                        }
                    }

                    if ($error === null) {
                        try {
                            $currentOfferId = $this->offerRepository->saveOffer(
                                $isEdit ? $offerId : null,
                                $titre,
                                $company,
                                $lieu,
                                $remuneration,
                                $dureeSemaines,
                                $description,
                                $competenceIds,
                                $newSkillNames
                            );

                            if ($isEdit) {
                                $success = 'Offre mise à jour avec succès.';
                            } else {
                                $offerId = $currentOfferId;
                                $isEdit = true;
                                $success = 'Offre créée avec succès.';
                            }

                            $reloadedOffer = $this->offerRepository->findOfferById($currentOfferId);
                            if ($reloadedOffer) {
                                $offer = array_merge($offer, $reloadedOffer);
                            }

                            $offer['competence_ids'] = $this->offerRepository->getOfferSkillIds($currentOfferId);
                            $offer['new_skill_names'] = [];
                            $skills = $this->offerRepository->getAllSkills();
                        } catch (\Throwable $e) {
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

    /**
     * Supprime une offre et toutes ses données liées (candidatures, wishlist, compétences).
     * La suppression en cascade est gérée par OfferRepository::deleteOffer() via une transaction.
     */
    public function delete(int $offerId): void
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        try {
            $this->offerRepository->deleteOffer($offerId);
        } catch (\Throwable $e) {
        }

        header('Location: /pilot-offres');
        exit;
    }
}