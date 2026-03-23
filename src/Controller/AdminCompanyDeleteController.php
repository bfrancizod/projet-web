<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;

class AdminCompanyDeleteController
{
    public function delete(int $companyId): void
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['administrateur', 'pilote'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE offres
                SET entreprise_id = NULL
                WHERE entreprise_id = :id
            ");
            $stmt->execute(['id' => $companyId]);

            $stmt = $pdo->prepare("
                DELETE FROM entreprises
                WHERE id = :id
            ");
            $stmt->execute(['id' => $companyId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        header('Location: /admin-entreprises');
        exit;
    }
}