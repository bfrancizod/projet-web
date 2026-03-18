<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;

class AdminPilotDeleteController
{
    public function delete(int $pilotId): void
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'administrateur') {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            DELETE FROM users
            WHERE id = :id
              AND role = 'pilote'
        ");
        $stmt->execute(['id' => $pilotId]);

        header('Location: /admin-pilotes');
        exit;
    }
}