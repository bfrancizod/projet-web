<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\WishlistRepository;
use App\Security\Csrf;
use Twig\Environment;

/**
 * Contrôleur de la wishlist étudiant
 *
 * Accessible uniquement aux étudiants connectés.
 * Gère l'ajout et la suppression d'offres dans la wishlist via des formulaires POST.
 * La méthode assertStudent() factorise le contrôle d'accès commun aux deux actions.
 */
class WishlistController
{
    private Environment $twig;
    private WishlistRepository $wishlistRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->wishlistRepository = new WishlistRepository(Database::getConnection());
    }

    /**
     * Ajoute une offre à la wishlist et redirige vers la page de l'offre.
     * Vérifie que l'offre existe avant d'ajouter (INSERT IGNORE dans le repo évite les doublons).
     */
    public function add(int $offerId): void
    {
        $this->assertStudent();
        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $userId = (int) ($_SESSION['user']['id'] ?? 0);

        if (!$this->wishlistRepository->offerExists($offerId)) {
            http_response_code(404);
            exit('Offre introuvable.');
        }

        $this->wishlistRepository->addOfferToWishlist($userId, $offerId);

        header('Location: /offres/' . $offerId);
        exit;
    }

    /** Retire une offre de la wishlist et redirige vers la page wishlist de l'étudiant */
    public function remove(int $offerId): void
    {
        $this->assertStudent();
        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $userId = (int) ($_SESSION['user']['id'] ?? 0);

        $this->wishlistRepository->removeOfferFromWishlist($userId, $offerId);

        header('Location: /etudiant-wishlist');
        exit;
    }

    /**
     * Vérifie que l'utilisateur est un étudiant connecté.
     * Factorisé pour éviter la duplication entre add() et remove().
     */
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