<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\CompanyCommentRepository;
use App\Repository\OfferRepository;
use Twig\Environment;

/**
 * Contrôleur public des offres de stage
 *
 * Pages accessibles sans connexion.
 * Gère la liste filtrée/paginée (/offres) et le détail d'une offre (/offres/{id}).
 */
class OfferController
{
    private Environment $twig;
    private OfferRepository $offerRepository;
    private CompanyCommentRepository $companyCommentRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;

        // Partage la même connexion PDO entre les deux repositories
        $pdo = Database::getConnection();
        $this->offerRepository = new OfferRepository($pdo);
        $this->companyCommentRepository = new CompanyCommentRepository($pdo);
    }

    /**
     * Liste paginée des offres avec 5 filtres combinables :
     * recherche texte, compétences, localisation, durée max, salaire min.
     * 6 offres par page, tri configurable (récent, salaire, durée).
     */
    public function index(): string
    {
        $searchQuery = trim((string) ($_GET['search_query'] ?? ''));
        $skillsQuery = trim((string) ($_GET['skills'] ?? ''));
        $locationQuery = trim((string) ($_GET['location'] ?? ''));
        $durationQuery = trim((string) ($_GET['duration'] ?? ''));
        $salaryQuery = trim((string) ($_GET['salary'] ?? ''));
        $sortQuery = trim((string) ($_GET['sort'] ?? 'recent'));

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 6;

        $totalOffers = $this->offerRepository->countPublicOffers(
            $searchQuery,
            $skillsQuery,
            $locationQuery,
            $durationQuery,
            $salaryQuery
        );

        $totalPages = max(1, (int) ceil($totalOffers / $perPage));

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;

        $offers = $this->offerRepository->findPublicOffersPaginated(
            $searchQuery,
            $skillsQuery,
            $locationQuery,
            $durationQuery,
            $salaryQuery,
            $sortQuery,
            $perPage,
            $offset
        );

        return $this->twig->render('offers.html.twig', [
            'offers' => $offers,
            'search_query' => $searchQuery,
            'skills_query' => $skillsQuery,
            'location_query' => $locationQuery,
            'duration_query' => $durationQuery,
            'salary_query' => $salaryQuery,
            'sort_query' => $sortQuery,
            'current_page' => $page,
            'total_pages' => $totalPages,
        ]);
    }

    /**
     * Page de détail d'une offre.
     * Si l'utilisateur est un étudiant connecté, vérifie en plus
     * s'il a déjà postulé et si l'offre est dans sa wishlist (pour adapter les boutons).
     * Affiche aussi le dernier commentaire sur l'entreprise si elle est référencée.
     */
    public function show(int $id): string
    {
        $offer = $this->offerRepository->findPublicOfferDetailById($id);

        if (!$offer) {
            http_response_code(404);
            return 'Offre introuvable.';
        }

        $skills = $this->offerRepository->findOfferSkillsByOfferId($id);

        $isInWishlist = false;
        $hasApplied = false;

        if (isset($_SESSION['user']) && ($_SESSION['user']['role'] ?? null) === 'etudiant') {
            $userId = (int) $_SESSION['user']['id'];

            $isInWishlist = $this->offerRepository->isOfferInWishlist($userId, $id);
            $hasApplied = $this->offerRepository->hasStudentAppliedToOffer($userId, $id);
        }

        $latestComments = [];
        $commentsCount = 0;

        if ($offer->getEntrepriseId() !== null) {
            $companyId = $offer->getEntrepriseId();
            $latestComments = $this->companyCommentRepository->findLatestByCompanyId($companyId, 1);
            $commentsCount = $this->companyCommentRepository->countByCompanyId($companyId);
        }

        return $this->twig->render('offer-detail.html.twig', [
            'offer' => $offer,
            'skills' => $skills,
            'is_in_wishlist' => $isInWishlist,
            'has_applied' => $hasApplied,
            'latest_comments' => $latestComments,
            'comments_count' => $commentsCount,
        ]);
    }
}