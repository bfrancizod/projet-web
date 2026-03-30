<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;
use Twig\Environment;

class AdminPromotionFormController
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

    public function edit(int $promotionId): string
    {
        return $this->handleForm($promotionId);
    }

    private function handleForm(?int $promotionId): string
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'administrateur') {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();
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
            $stmt = $pdo->prepare("
                SELECT id, label, academic_year, is_active
                FROM promotions
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $promotionId]);
            $existingPromotion = $stmt->fetch();

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
            } elseif (!preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
                $error = 'Le format de l’année doit être du type 2025-2026.';
            } else {
                if ($isEdit) {
                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM promotions
                        WHERE label = :label
                          AND academic_year = :academic_year
                          AND id != :id
                        LIMIT 1
                    ");
                    $stmt->execute([
                        'label' => $label,
                        'academic_year' => $academicYear,
                        'id' => $promotionId,
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM promotions
                        WHERE label = :label
                          AND academic_year = :academic_year
                        LIMIT 1
                    ");
                    $stmt->execute([
                        'label' => $label,
                        'academic_year' => $academicYear,
                    ]);
                }

                if ($stmt->fetch()) {
                    $error = 'Cette promotion existe déjà pour cette année.';
                } else {
                    if ($isEdit) {
                        $stmt = $pdo->prepare("
                            UPDATE promotions
                            SET label = :label,
                                academic_year = :academic_year,
                                is_active = :is_active
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            'id' => $promotionId,
                            'label' => $label,
                            'academic_year' => $academicYear,
                            'is_active' => $isActive,
                        ]);

                        $success = 'Promotion modifiée avec succès.';
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO promotions (label, academic_year, is_active)
                            VALUES (:label, :academic_year, :is_active)
                        ");
                        $stmt->execute([
                            'label' => $label,
                            'academic_year' => $academicYear,
                            'is_active' => $isActive,
                        ]);

                        $success = 'Promotion créée avec succès.';
                        $promotion = [
                            'id' => (int) $pdo->lastInsertId(),
                            'label' => $label,
                            'academic_year' => $academicYear,
                            'is_active' => $isActive,
                        ];
                        $isEdit = true;
                    }
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
}