<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;

class AdminPromotionDeleteController
{
    public function delete(int $promotionId): void
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'administrateur') {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            DELETE FROM promotions
            WHERE id = :id
        ");
        $stmt->execute(['id' => $promotionId]);

        header('Location: /admin-promotions');
        exit;
    }
}