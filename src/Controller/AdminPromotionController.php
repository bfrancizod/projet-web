<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\PromotionRepository;
use App\Security\Csrf;
use Twig\Environment;

/**
 * Contrôleur de gestion des promotions (admin uniquement)
 *
 * Une promotion représente un groupe d'étudiants sur une année académique.
 * L'identifiant unique d'une promotion est la combinaison label + academic_year.
 */
class AdminPromotionController
{
    private Environment $twig;
    private PromotionRepository $promotionRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->promotionRepository = new PromotionRepository(Database::getConnection());
    }

    /** Liste toutes les promotions avec leur nombre d'étudiants et de pilotes associés */
    public function index(): string
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'administrateur') {
            header('Location: /connexion');
            exit;
        }

        $search = trim((string) ($_GET['q'] ?? ''));

        return $this->twig->render('admin-promotions.html.twig', [
            'promotions' => $this->promotionRepository->findAllWithStats($search),
            'search' => $search,
        ]);
    }

    /** Affiche le formulaire de création d'une promotion */
    public function create(): string
    {
        return $this->handleForm(null);
    }

    /** Affiche le formulaire d'édition d'une promotion existante */
    public function edit(int $promotionId): string
    {
        return $this->handleForm($promotionId);
    }

    /**
     * Gère le formulaire de création et d'édition d'une promotion (méthode mutualisée).
     *
     * Validations :
     * - Tous les champs obligatoires remplis
     * - Format année académique : YYYY-YYYY (ex: 2025-2026)
     * - Pas de doublon label + academic_year (en ignorant la promotion courante en édition)
     */
    private function handleForm(?int $promotionId): string
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'administrateur') {
            header('Location: /connexion');
            exit;
        }

        $isEdit = $promotionId !== null;
        $error = null;
        $success = null;

        $promotion = [
            'id' => null,
            'label' => '',
            'academic_year' => '',
            'is_active' => 1,
        ];

        if ($isEdit) {
            $existingPromotion = $this->promotionRepository->findById($promotionId);

            if (!$existingPromotion) {
                http_response_code(404);
                return 'Promotion introuvable.';
            }

            $promotion = $existingPromotion;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

            $label = trim((string) ($_POST['label'] ?? ''));
            $academicYear = trim((string) ($_POST['academic_year'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            $promotion['label'] = $label;
            $promotion['academic_year'] = $academicYear;
            $promotion['is_active'] = $isActive;

            if ($label === '' || $academicYear === '') {
                $error = 'Merci de remplir tous les champs obligatoires.';
            // Vérifie le format YYYY-YYYY (ex: 2025-2026)
            } elseif (!preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
                $error = 'Le format de l’année doit être du type 2025-2026.';
            } elseif ($this->promotionRepository->existsByLabelAndYear($label, $academicYear, $isEdit ? $promotionId : null)) {
                $error = 'Cette promotion existe déjà pour cette année.';
            } else {
                try {
                    if ($isEdit) {
                        $this->promotionRepository->update(
                            $promotionId,
                            $label,
                            $academicYear,
                            $isActive
                        );

                        $success = 'Promotion modifiée avec succès.';
                    } else {
                        $promotionId = $this->promotionRepository->create(
                            $label,
                            $academicYear,
                            $isActive
                        );

                        $success = 'Promotion créée avec succès.';
                        $isEdit = true;
                    }

                    $reloadedPromotion = $this->promotionRepository->findById($promotionId);
                    if ($reloadedPromotion) {
                        $promotion = $reloadedPromotion;
                    }
                } catch (\Throwable $e) {
                    $error = $isEdit
                        ? 'Erreur lors de la modification de la promotion.'
                        : 'Erreur lors de la création de la promotion.';
                }
            }
        }

        return $this->twig->render('admin-promotion-form.html.twig', [
            'promotion' => $promotion,
            'is_edit' => $isEdit,
            'error' => $error,
            'success' => $success,
        ]);
    }

    /**
     * Supprime une promotion.
     * Les étudiants et pilotes liés ne sont pas supprimés,
     * leur promotion_id devient NULL (ON DELETE SET NULL en BDD).
     */
    public function delete(int $promotionId): void
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'administrateur') {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $this->promotionRepository->delete($promotionId);

        header('Location: /admin-promotions');
        exit;
    }
}