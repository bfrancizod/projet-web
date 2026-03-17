<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;
use Twig\Environment;

class PilotStudentDeleteController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function delete(int $studentId): void
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'pilote') {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("DELETE FROM candidatures WHERE student_user_id = :id");
            $stmt->execute(['id' => $studentId]);

            $stmt = $pdo->prepare("DELETE FROM student_wishlist WHERE user_id = :id");
            $stmt->execute(['id' => $studentId]);

            $stmt = $pdo->prepare("DELETE FROM student_profiles WHERE user_id = :id");
            $stmt->execute(['id' => $studentId]);

            $stmt = $pdo->prepare("
                DELETE FROM users
                WHERE id = :id AND role = 'etudiant'
            ");
            $stmt->execute(['id' => $studentId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            http_response_code(500);
            exit('Erreur lors de la suppression.');
        }

        header('Location: /pilot-etudiants');
        exit;
    }
}