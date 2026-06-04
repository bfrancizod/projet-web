<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\CompanyCommentRepository;
use App\Repository\CompanyRepository;
use App\Security\Csrf;
use Twig\Environment;

/**
 * Contrôleur de gestion des entreprises
 *
 * - Liste : accessible aux administrateurs ET pilotes
 * - Création / édition / suppression : administrateurs uniquement
 *
 * Le formulaire gère à la fois les données de l'entreprise ET le commentaire
 * de l'utilisateur connecté (upsert via CompanyCommentRepository).
 * Les deux repositories partagent la même connexion PDO.
 */
class AdminCompanyController
{
    private Environment $twig;
    private CompanyRepository $companyRepository;
    private CompanyCommentRepository $companyCommentRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;

        // Partage la même connexion PDO entre les deux repositories
        $pdo = Database::getConnection();
        $this->companyRepository = new CompanyRepository($pdo);
        $this->companyCommentRepository = new CompanyCommentRepository($pdo);
    }

    /** Liste toutes les entreprises — accessible aux admins et pilotes */
    public function index(): string
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['administrateur', 'pilote'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        $search = trim((string) ($_GET['q'] ?? ''));
        $companies = $this->companyRepository->findAll($search);

        return $this->twig->render('admin-companies.html.twig', [
            'companies' => $companies,
            'search' => $search,
        ]);
    }

    /** Affiche le formulaire de création d'une nouvelle entreprise */
    public function create(): string
    {
        return $this->handleForm(null);
    }

    /** Affiche le formulaire d'édition d'une entreprise existante */
    public function edit(int $companyId): string
    {
        return $this->handleForm($companyId);
    }

    /**
     * Gère le formulaire de création ET d'édition d'une entreprise (méthode mutualisée).
     *
     * En édition ($companyId non null) :
     * - Charge les données existantes pour pré-remplir le formulaire
     * - Charge le commentaire de l'utilisateur courant sur cette entreprise
     * - Charge tous les commentaires pour les afficher
     *
     * Validations : nom obligatoire, SIRET 14 chiffres, URL valide, longueurs max,
     * unicité du nom (en ignorant l'entreprise courante en édition).
     *
     * Après sauvegarde réussie : recharge les données depuis la BDD pour éviter
     * d'afficher des données périmées (pattern post-redirect-get allégé).
     */
    private function handleForm(?int $companyId): string
    {
        if (
            !isset($_SESSION['user'])
            || ($_SESSION['user']['role'] ?? null) !== 'administrateur'
        ) {
            header('Location: /connexion');
            exit;
        }

        $currentUserId = (int) $_SESSION['user']['id'];

        $isEdit = $companyId !== null; // true = édition, false = création
        $error = null;
        $success = null;

        // Valeurs par défaut pour le formulaire vide (création)
        $company = [
            'id' => null,
            'nom' => '',
            'siret' => '',
            'secteur' => '',
            'ville' => '',
            'site_web' => '',
            'note' => '',
        ];

        $userComment = [
            'commentaire' => '',
        ];

        $allComments = [];

        if ($isEdit) {
            $existingCompany = $this->companyRepository->findById($companyId);

            if (!$existingCompany) {
                http_response_code(404);
                return 'Entreprise introuvable.';
            }

            $company = $existingCompany;

            $existingUserComment = $this->companyCommentRepository->findByCompanyIdAndUserId($companyId, $currentUserId);
            if ($existingUserComment) {
                $userComment['commentaire'] = (string) ($existingUserComment['commentaire'] ?? '');
            }

            $allComments = $this->companyCommentRepository->findByCompanyId($companyId);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

            $nom = trim((string) ($_POST['nom'] ?? ''));
            // Supprime tous les caractères non numériques du SIRET (espaces, tirets, etc.)
            $siret = preg_replace('/\D/', '', (string) ($_POST['siret'] ?? ''));
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
            $company['siret'] = $siret;
            $company['secteur'] = $secteur;
            $company['ville'] = $ville;
            $company['site_web'] = $siteWeb;
            $company['note'] = $noteRaw;

            $userComment['commentaire'] = $commentaire;

            if ($error === null && $nom === '') {
                $error = "Le nom de l'entreprise est obligatoire.";
            }

            if ($error === null && mb_strlen($nom) > 150) {
                $error = "Le nom de l'entreprise ne doit pas dépasser 150 caractères.";
            }

            if ($error === null && mb_strlen($secteur) > 100) {
                $error = 'Le secteur ne doit pas dépasser 100 caractères.';
            }

            if ($error === null && mb_strlen($ville) > 100) {
                $error = 'La ville ne doit pas dépasser 100 caractères.';
            }

            if ($error === null && mb_strlen($commentaire) > 2000) {
                $error = 'Le commentaire ne doit pas dépasser 2000 caractères.';
            }

            if ($error === null && $siret !== '' && strlen($siret) !== 14) {
                $error = 'Le SIRET doit contenir 14 chiffres.';
            }

            if ($error === null && $siteWeb !== '' && !filter_var($siteWeb, FILTER_VALIDATE_URL)) {
                $error = "L'URL du site web est invalide.";
            }

            if ($error === null && $this->companyRepository->nameExists($nom, $isEdit ? $companyId : null)) {
                $error = 'Une entreprise avec ce nom existe déjà.';
            }

            if ($error === null) {
                try {
                    // Les champs optionnels sont stockés NULL en BDD plutôt que chaîne vide
                    // pour pouvoir distinguer "non renseigné" de "renseigné vide"
                    $siretValue = $siret !== '' ? $siret : null;
                    $secteurValue = $secteur !== '' ? $secteur : null;
                    $villeValue = $ville !== '' ? $ville : null;
                    $siteWebValue = $siteWeb !== '' ? $siteWeb : null;

                    if ($isEdit) {
                        $this->companyRepository->update(
                            $companyId,
                            $nom,
                            $siretValue,
                            $secteurValue,
                            $villeValue,
                            $siteWebValue,
                            $note
                        );

                        if ($commentaire !== '') {
                            $this->companyCommentRepository->upsert(
                                $companyId,
                                $currentUserId,
                                $commentaire
                            );
                        }

                        $success = 'Entreprise mise à jour avec succès.';
                    } else {
                        $companyId = $this->companyRepository->create(
                            $nom,
                            $siretValue,
                            $secteurValue,
                            $villeValue,
                            $siteWebValue,
                            $note
                        );

                        if ($commentaire !== '') {
                            $this->companyCommentRepository->upsert(
                                $companyId,
                                $currentUserId,
                                $commentaire
                            );
                        }

                        $isEdit = true;
                        $success = 'Entreprise créée avec succès.';
                    }

                    // Rechargement depuis la BDD après sauvegarde : garantit que le template
                    // affiche les données réellement persistées (ex: note calculée, timestamps)
                    // plutôt que les valeurs du formulaire qui pourraient différer légèrement
                    $reloadedCompany = $this->companyRepository->findById($companyId);
                    if ($reloadedCompany) {
                        $company = $reloadedCompany;
                    }

                    $reloadedUserComment = $this->companyCommentRepository->findByCompanyIdAndUserId($companyId, $currentUserId);
                    $userComment['commentaire'] = $reloadedUserComment['commentaire'] ?? '';

                    $allComments = $this->companyCommentRepository->findByCompanyId($companyId);
                } catch (\Throwable $e) {
                    $error = $isEdit
                        ? "Erreur lors de la mise à jour de l'entreprise."
                        : "Erreur lors de la création de l'entreprise.";
                }
            }
        }

        return $this->twig->render('admin-company-form.html.twig', [
            'company' => $company,
            'user_comment' => $userComment,
            'company_comments' => $allComments,
            'is_edit' => $isEdit,
            'error' => $error,
            'success' => $success,
        ]);
    }

    /**
     * Supprime une entreprise et toutes ses données liées (offres, candidatures, wishlist, commentaires).
     * La suppression en cascade est gérée par CompanyRepository::delete() via une transaction.
     */
    public function delete(int $companyId): void
    {
        if (
            !isset($_SESSION['user'])
            || ($_SESSION['user']['role'] ?? null) !== 'administrateur'
        ) {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        try {
            $this->companyRepository->delete($companyId);
        } catch (\Throwable $e) {
            // On journalise l'échec pour le diagnostic et on en informe l'utilisateur
            // (sans ce log, une suppression échouée passait inaperçue, l'utilisateur croyait avoir réussi)
            error_log('[AdminCompanyController::delete] Échec suppression entreprise #' . $companyId . ' : ' . $e->getMessage());
            header('Location: /admin-entreprises?error=suppression_echouee');
            exit;
        }

        header('Location: /admin-entreprises');
        exit;
    }
}