<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;
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

        $pilot = [
            'id' => null,
            'nom' => '',
            'prenom' => '',
            'email' => '',
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
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

            $nom = trim((string) ($_POST['nom'] ?? ''));
            $prenom = trim((string) ($_POST['prenom'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            $pilot['nom'] = $nom;
            $pilot['prenom'] = $prenom;
            $pilot['email'] = $email;

            if ($nom === '' || $prenom === '' || $email === '') {
                $error = 'Merci de remplir tous les champs obligatoires.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Adresse email invalide.';
            } elseif (!$isEdit && mb_strlen($password) < 8) {
                $error = 'Le mot de passe initial doit contenir au moins 8 caractères.';
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
                    $stmt->execute(['email' => $email]);
                }

                if ($stmt->fetch()) {
                    $error = 'Cet email est déjà utilisé.';
                } else {
                    try {
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

                            $success = 'Compte pilote mis à jour avec succès.';
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

                            $pilotId = (int) $pdo->lastInsertId();
                            $isEdit = true;
                            $success = 'Compte pilote créé avec succès.';
                        }

                        $stmt = $pdo->prepare("
                            SELECT id, nom, prenom, email
                            FROM users
                            WHERE id = :id
                              AND role = 'pilote'
                            LIMIT 1
                        ");
                        $stmt->execute(['id' => $pilotId]);
                        $pilot = $stmt->fetch();
                    } catch (\Throwable $e) {
                        $error = $isEdit
                            ? 'Erreur lors de la mise à jour du pilote.'
                            : 'Erreur lors de la création du pilote.';
                    }
                }
            }
        }

        return $this->twig->render('admin-pilot-form.html.twig', [
            'pilot' => $pilot,
            'is_edit' => $isEdit,
            'error' => $error,
            'success' => $success,
        ]);
    }
}