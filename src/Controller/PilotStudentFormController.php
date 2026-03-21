<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;
use Twig\Environment;

class PilotStudentFormController
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

    public function edit(int $studentId): string
    {
        return $this->handleForm($studentId);
    }

    private function handleForm(?int $studentId): string
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();
        $isEdit = $studentId !== null;
        $error = null;
        $success = null;

        $promotionsStmt = $pdo->query("
            SELECT id, label
            FROM promotions
            WHERE is_active = 1
            ORDER BY label ASC
        ");
        $promotions = $promotionsStmt->fetchAll();

        $student = [
            'id' => null,
            'nom' => '',
            'prenom' => '',
            'email' => '',
            'formation' => '',
            'promotion_id' => null,
            'status' => 'en_recherche',
            'last_activity' => null,
        ];

        if ($isEdit) {
            $stmt = $pdo->prepare("
                SELECT
                    u.id,
                    u.nom,
                    u.prenom,
                    u.email,
                    sp.formation,
                    sp.promotion_id,
                    sp.status,
                    sp.last_activity
                FROM users u
                INNER JOIN student_profiles sp ON sp.user_id = u.id
                WHERE u.id = :id
                  AND u.role = 'etudiant'
                LIMIT 1
            ");
            $stmt->execute(['id' => $studentId]);
            $existingStudent = $stmt->fetch();

            if (!$existingStudent) {
                http_response_code(404);
                return 'Étudiant introuvable.';
            }

            $student = $existingStudent;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

            $nom = trim((string) ($_POST['nom'] ?? ''));
            $prenom = trim((string) ($_POST['prenom'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $formation = trim((string) ($_POST['formation'] ?? ''));
            $promotionIdRaw = (string) ($_POST['promotion_id'] ?? '');
            $status = trim((string) ($_POST['status'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            $promotionId = ctype_digit($promotionIdRaw) ? (int) $promotionIdRaw : null;
            $allowedStatuses = ['sans_stage', 'en_recherche', 'stage_trouve', 'stage_valide'];

            $student['nom'] = $nom;
            $student['prenom'] = $prenom;
            $student['email'] = $email;
            $student['formation'] = $formation;
            $student['promotion_id'] = $promotionId;
            $student['status'] = $status;

            if ($nom === '' || $prenom === '' || $email === '' || $formation === '') {
                $error = 'Merci de remplir tous les champs obligatoires.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Adresse email invalide.';
            } elseif ($promotionId === null) {
                $error = 'Merci de choisir une promotion.';
            } elseif (!in_array($status, $allowedStatuses, true)) {
                $error = 'Statut invalide.';
            } elseif (!$isEdit && mb_strlen($password) < 8) {
                $error = 'Le mot de passe initial doit contenir au moins 8 caractères.';
            } else {
                $promotionCheck = $pdo->prepare("
                    SELECT id
                    FROM promotions
                    WHERE id = :id AND is_active = 1
                    LIMIT 1
                ");
                $promotionCheck->execute(['id' => $promotionId]);

                if (!$promotionCheck->fetchColumn()) {
                    $error = 'Promotion invalide.';
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
                            'id' => $studentId,
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
                        $error = 'Cet email est déjà utilisé par un autre compte.';
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
                                      AND role = 'etudiant'
                                ");
                                $stmt->execute([
                                    'id' => $studentId,
                                    'nom' => $nom,
                                    'prenom' => $prenom,
                                    'email' => $email,
                                ]);

                                $stmt = $pdo->prepare("
                                    UPDATE student_profiles
                                    SET formation = :formation,
                                        promotion_id = :promotion_id,
                                        status = :status,
                                        last_activity = CURDATE()
                                    WHERE user_id = :user_id
                                ");
                                $stmt->execute([
                                    'user_id' => $studentId,
                                    'formation' => $formation,
                                    'promotion_id' => $promotionId,
                                    'status' => $status,
                                ]);

                                $success = 'Profil étudiant mis à jour avec succès.';
                            } else {
                                $stmt = $pdo->prepare("
                                    INSERT INTO users (nom, prenom, email, password_hash, role)
                                    VALUES (:nom, :prenom, :email, :password_hash, 'etudiant')
                                ");
                                $stmt->execute([
                                    'nom' => $nom,
                                    'prenom' => $prenom,
                                    'email' => $email,
                                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                                ]);

                                $newStudentId = (int) $pdo->lastInsertId();

                                $stmt = $pdo->prepare("
                                    INSERT INTO student_profiles (user_id, formation, promotion_id, status, last_activity)
                                    VALUES (:user_id, :formation, :promotion_id, :status, CURDATE())
                                ");
                                $stmt->execute([
                                    'user_id' => $newStudentId,
                                    'formation' => $formation,
                                    'promotion_id' => $promotionId,
                                    'status' => $status,
                                ]);

                                $studentId = $newStudentId;
                                $isEdit = true;
                                $student['id'] = $newStudentId;
                                $student['last_activity'] = date('Y-m-d');
                                $success = 'Étudiant créé avec succès.';
                            }

                            $pdo->commit();

                            $stmt = $pdo->prepare("
                                SELECT
                                    u.id,
                                    u.nom,
                                    u.prenom,
                                    u.email,
                                    sp.formation,
                                    sp.promotion_id,
                                    sp.status,
                                    sp.last_activity
                                FROM users u
                                INNER JOIN student_profiles sp ON sp.user_id = u.id
                                WHERE u.id = :id
                                  AND u.role = 'etudiant'
                                LIMIT 1
                            ");
                            $stmt->execute(['id' => $studentId]);
                            $student = $stmt->fetch();
                        } catch (\Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }

                            $error = $isEdit
                                ? 'Erreur lors de la mise à jour du profil.'
                                : 'Erreur lors de la création de l’étudiant.';
                        }
                    }
                }
            }
        }

        return $this->twig->render('pilot-student-form.html.twig', [
            'student' => $student,
            'promotions' => $promotions,
            'is_edit' => $isEdit,
            'error' => $error,
            'success' => $success,
        ]);
    }
}