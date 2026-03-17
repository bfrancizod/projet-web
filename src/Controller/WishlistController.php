<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;
use Twig\Environment;

class WishlistController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function add(int $offerId): void
    {
        $this->assertStudent();
        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $pdo = Database::getConnection();
        $userId = (int) $_SESSION['user']['id'];

        $stmtOffer = $pdo->prepare("SELECT id FROM offres WHERE id = :id LIMIT 1");
        $stmtOffer->execute(['id' => $offerId]);

        if (!$stmtOffer->fetchColumn()) {
            http_response_code(404);
            exit('Offre introuvable.');
        }

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO student_wishlist (user_id, offre_id)
            VALUES (:user_id, :offre_id)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'offre_id' => $offerId,
        ]);

        header('Location: /offres/' . $offerId);
        exit;
    }

    public function remove(int $offerId): void
    {
        $this->assertStudent();
        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $pdo = Database::getConnection();
        $userId = (int) $_SESSION['user']['id'];

        $stmt = $pdo->prepare("
            DELETE FROM student_wishlist
            WHERE user_id = :user_id AND offre_id = :offre_id
        ");
        $stmt->execute([
            'user_id' => $userId,
            'offre_id' => $offerId,
        ]);

        header('Location: /offres/' . $offerId);
        exit;
    }

    private function assertStudent(): void
    {
        if (!isset($_SESSION['user'])) {
            header('Location: /connexion');
            exit;
        }

        if (($_SESSION['user']['role'] ?? null) !== 'etudiant') {
            http_response_code(403);
            exit('Accès refusé.');
        }
    }
}