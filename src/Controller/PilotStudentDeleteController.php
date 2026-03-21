<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;

class PilotStudentDeleteController
{
    public function delete(int $studentId): void
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("DELETE FROM student_wishlist WHERE user_id = :id");
            $stmt->execute(['id' => $studentId]);

            $stmt = $pdo->prepare("DELETE FROM candidatures WHERE student_user_id = :id");
            $stmt->execute(['id' => $studentId]);

            $stmt = $pdo->prepare("DELETE FROM student_profiles WHERE user_id = :id");
            $stmt->execute(['id' => $studentId]);

            $stmt = $pdo->prepare("
                DELETE FROM users
                WHERE id = :id
                  AND role = 'etudiant'
            ");
            $stmt->execute(['id' => $studentId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        if (($_SESSION['user']['role'] ?? null) === 'administrateur') {
            header('Location: /pilot-etudiants');
            exit;
        }

        header('Location: /pilot-etudiants');
        exit;
    }
}