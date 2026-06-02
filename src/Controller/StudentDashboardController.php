<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\ApplicationRepository;
use App\Repository\WishlistRepository;
use Twig\Environment;

/**
 * Contrôleur du tableau de bord étudiant
 *
 * Accessible uniquement aux étudiants connectés.
 * Affiche les statistiques (nombre de candidatures, taille de la wishlist)
 * et les 5 candidatures les plus récentes.
 */
class StudentDashboardController
{
    private Environment $twig;
    private ApplicationRepository $applicationRepository;
    private WishlistRepository $wishlistRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;

        // Les deux repositories partagent la même connexion PDO
        $pdo = Database::getConnection();
        $this->applicationRepository = new ApplicationRepository($pdo);
        $this->wishlistRepository = new WishlistRepository($pdo);
    }

    /** Affiche le dashboard avec les stats et les candidatures récentes de l'étudiant connecté */
    public function index(): string
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'etudiant') {
            header('Location: /connexion');
            exit;
        }

        $userId = (int) $_SESSION['user']['id'];

        $applicationsCount = $this->applicationRepository->countApplicationsByStudentUserId($userId);
        $wishlistCount = $this->wishlistRepository->countWishlistOffersByUserId($userId);
        $recentApplications = $this->applicationRepository->findRecentApplicationsByStudentUserId($userId, 5);

        return $this->twig->render('student-dashboard.html.twig', [
            'site_name' => 'Help Me Stage',
            'stats' => [
                'applications' => $applicationsCount,
                'wishlist' => $wishlistCount,
            ],
            'recent_applications' => $recentApplications,
        ]);
    }
}