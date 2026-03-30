<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;
use App\Support\PilotPromotionAccess;
use Twig\Environment;

class AdminPilotFormController
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

    public function edit(int $pilotId): string
    {
        return $this->handleForm($pilotId);
    }

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

        $promotionsStmt = $pdo->query("
            SELECT id, label, academic_year
            FROM promotions
            WHERE is_active = 1
            ORDER BY academic_year DESC, label ASC
        ");
        $promotions = $promotionsStmt->fetchAll();
        $availablePromotionIds = array_map(static fn(array $promotion): int => (int) $promotion['id'], $promotions);

        $pilot = [
            'id' => null,
            'nom' => '',
            'prenom' => '',
            'email' => '',
            'promotion_ids' => [],
        ];

        if ($isEdit) {
            $stmt = $pdo->prepare("
                SELECT id, nom, prenom, email
                FROM users
                WHERE id = :id
                  AND role = 'pilote'
                LIMIT 1
            ");
            $stmt->execute(['id' => $pilotId]);
            $existingPilot = $stmt->fetch();

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
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Adresse email invalide.';
            } elseif (!$isEdit && mb_strlen($password) < 8) {
                $error = 'Le mot de passe initial doit contenir au moins 8 caractères.';
            } elseif ($promotionIds === []) {
                $error = 'Merci de sélectionner au moins une promotion.';
            } elseif (array_diff($promotionIds, $availablePromotionIds) !== []) {
                $error = 'Une ou plusieurs promotions sélectionnées sont invalides.';
            } else {
                if ($isEdit) {
                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM users
                        WHERE email = :email
                          AND id != :id
                        LIMIT 1
                    ");
                    $stmt->execute([
                        'email' => $email,
                        'id' => $pilotId,
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM users
                        WHERE email = :email
                        LIMIT 1
                    ");
                    $stmt->execute([
                        'email' => $email,
                    ]);
                }

                if ($stmt->fetch()) {
                    $error = 'Cet email est déjà utilisé.';
                } else {
                    try {
                        $pdo->beginTransaction();

                        if ($isEdit) {
                            $stmt = $pdo->prepare("
                                UPDATE users
                                SET nom = :nom,
                                    prenom = :prenom,
                                    email = :email
                                WHERE id = :id
                                  AND role = 'pilote'
                            ");
                            $stmt->execute([
                                'id' => $pilotId,
                                'nom' => $nom,
                                'prenom' => $prenom,
                                'email' => $email,
                            ]);

                            $finalPilotId = (int) $pilotId;
                            $success = 'Pilote modifié avec succès.';
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO users (nom, prenom, email, password_hash, role)
                                VALUES (:nom, :prenom, :email, :password_hash, 'pilote')
                            ");
                            $stmt->execute([
                                'nom' => $nom,
                                'prenom' => $prenom,
                                'email' => $email,
                                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            ]);

                            $finalPilotId = (int) $pdo->lastInsertId();
                            $success = 'Pilote créé avec succès.';
                            $isEdit = true;
                        }

                        $deleteStmt = $pdo->prepare("
                            DELETE FROM pilot_promotions
                            WHERE pilot_user_id = :pilot_user_id
                        ");
                        $deleteStmt->execute([
                            'pilot_user_id' => $finalPilotId,
                        ]);

                        $insertStmt = $pdo->prepare("
                            INSERT INTO pilot_promotions (pilot_user_id, promotion_id)
                            VALUES (:pilot_user_id, :promotion_id)
                        ");

                        foreach ($promotionIds as $promotionId) {
                            $insertStmt->execute([
                                'pilot_user_id' => $finalPilotId,
                                'promotion_id' => $promotionId,
                            ]);
                        }

                        $pdo->commit();

                        $stmt = $pdo->prepare("
                            SELECT id, nom, prenom, email
                            FROM users
                            WHERE id = :id
                              AND role = 'pilote'
                            LIMIT 1
                        ");
                        $stmt->execute(['id' => $finalPilotId]);
                        $pilot = $stmt->fetch();
                        $pilot['promotion_ids'] = PilotPromotionAccess::getAssignedPromotionIds($pdo, $finalPilotId);
                    } catch (\Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $error = $isEdit
                            ? 'Erreur lors de la modification du pilote.'
                            : 'Erreur lors de la création du pilote.';
                    }
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
}