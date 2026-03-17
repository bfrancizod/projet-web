<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use Twig\Environment;

class StudentWishlistController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(): string
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'etudiant') {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();
        $userId = (int) $_SESSION['user']['id'];

        $stmt = $pdo->prepare("
            SELECT
                o.id,
                o.titre,
                o.entreprise,
                o.lieu,
                o.duree_semaines,
                o.remuneration
            FROM student_wishlist sw
            INNER JOIN offres o ON o.id = sw.offre_id
            WHERE sw.user_id = :user_id
            ORDER BY sw.created_at DESC
        ");
        $stmt->execute(['user_id' => $userId]);
        $offers = $stmt->fetchAll();

        return $this->twig->render('student-wishlist.html.twig', [
            'site_name' => 'Help Me Stage',
            'offers' => $offers,
        ]);
    }
}