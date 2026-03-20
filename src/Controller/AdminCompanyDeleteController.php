<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;

class AdminCompanyDeleteController
{
    public function delete(int $companyId): void
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'administrateur') {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            DELETE FROM entreprises
            WHERE id = :id
        ");
        $stmt->execute(['id' => $companyId]);

        header('Location: /admin-entreprises');
        exit;
    }
}