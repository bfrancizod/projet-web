<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\CompanyCommentRepository;
use App\Repository\CompanyRepository;
use App\Security\Csrf;
use Twig\Environment;

class AdminCompanyController
{
    private Environment $twig;
    private CompanyRepository $companyRepository;
    private CompanyCommentRepository $companyCommentRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;

        $pdo = Database::getConnection();
        $this->companyRepository = new CompanyRepository($pdo);
        $this->companyCommentRepository = new CompanyCommentRepository($pdo);
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

        $search = trim((string) ($_GET['q'] ?? ''));
        $companies = $this->companyRepository->findAll($search);

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
            || ($_SESSION['user']['role'] ?? null) !== 'administrateur'
        ) {
            header('Location: /connexion');
            exit;
        }

        $currentUserId = (int) $_SESSION['user']['id'];

        $isEdit = $companyId !== null;
        $error = null;
        $success = null;

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
        }

        header('Location: /admin-entreprises');
        exit;
    }
}