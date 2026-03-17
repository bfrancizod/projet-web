<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use Twig\Environment;

class OfferDetailController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function show(int $id): string
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT *
            FROM offres
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);

        $offer = $stmt->fetch();

        if (!$offer) {
            http_response_code(404);
            return 'Offre introuvable.';
        }

        $isInWishlist = false;

        if (isset($_SESSION['user']) && ($_SESSION['user']['role'] ?? null) === 'etudiant') {
            $stmtWishlist = $pdo->prepare("
                SELECT 1
                FROM student_wishlist
                WHERE user_id = :user_id AND offre_id = :offre_id
                LIMIT 1
            ");
            $stmtWishlist->execute([
                'user_id' => (int) $_SESSION['user']['id'],
                'offre_id' => $id,
            ]);

            $isInWishlist = (bool) $stmtWishlist->fetchColumn();
        }

        return $this->twig->render('offer-detail.html.twig', [
            'site_name' => 'Help Me Stage',
            'offer' => $offer,
            'is_in_wishlist' => $isInWishlist,
        ]);
    }
}