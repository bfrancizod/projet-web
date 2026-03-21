<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;

class PilotOfferDeleteController
{
    public function delete(int $offerId): void
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

            $stmt = $pdo->prepare("DELETE FROM student_wishlist WHERE offre_id = :id");
            $stmt->execute(['id' => $offerId]);

            $stmt = $pdo->prepare("DELETE FROM candidatures WHERE offre_id = :id");
            $stmt->execute(['id' => $offerId]);

            $stmt = $pdo->prepare("DELETE FROM offre_competence WHERE offre_id = :id");
            $stmt->execute(['id' => $offerId]);

            $stmt = $pdo->prepare("DELETE FROM offres WHERE id = :id");
            $stmt->execute(['id' => $offerId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        header('Location: /pilot-offres');
        exit;
    }
}