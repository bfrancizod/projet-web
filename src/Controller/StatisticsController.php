<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\StatisticsRepository;
use Twig\Environment;

/**
 * Contrôleur des statistiques de la plateforme
 *
 * Accessible à tout utilisateur connecté (pilote ou admin).
 * Agrège les données clés : répartition par durée, top wishlist, totaux, moyennes.
 */
class StatisticsController
{
    private Environment $twig;
    private StatisticsRepository $statisticsRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->statisticsRepository = new StatisticsRepository(Database::getConnection());
    }

    /**
     * Affiche la page de statistiques.
     * $maxWishlistCount est calculé côté PHP (pas SQL) pour éviter une requête
     * supplémentaire — utilisé dans le template pour calculer les largeurs de barres en %.
     */
    public function index(): string
    {
        if (!isset($_SESSION['user'])) {
            header('Location: /connexion');
            exit;
        }

        $offersByDuration = $this->statisticsRepository->getOffersByDuration();
        $topWishlistOffers = $this->statisticsRepository->getTopWishlistOffers(5);
        $totalOffers = $this->statisticsRepository->countOffers();
        $averageApplicationsPerOffer = $this->statisticsRepository->getAverageApplicationsPerOffer();

        $maxWishlistCount = 0;
        foreach ($topWishlistOffers as $wishlistStat) {
            $count = (int) ($wishlistStat['wishlist_count'] ?? 0);
            if ($count > $maxWishlistCount) {
                $maxWishlistCount = $count;
            }
        }

        return $this->twig->render('offers-statistics.html.twig', [
            'offers_by_duration' => $offersByDuration,
            'top_wishlist_offers' => $topWishlistOffers,
            'total_offers' => $totalOffers,
            'average_applications_per_offer' => $averageApplicationsPerOffer,
            'max_wishlist_count' => $maxWishlistCount,
        ]);
    }
}