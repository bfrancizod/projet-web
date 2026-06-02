<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\ApplicationRepository;
use App\Security\Csrf;
use Twig\Environment;

/**
 * Contrôleur de gestion des candidatures (pilote et admin)
 *
 * Permet aux pilotes et admins de consulter toutes les candidatures et de
 * changer leur statut (acceptée / refusée).
 * La mise à jour du statut synchronise aussi le statut de l'étudiant dans student_profiles.
 */
class PilotApplicationController
{
    private Environment $twig;
    private ApplicationRepository $applicationRepository;

    /** Nombre de candidatures affichées par page */
    private const PER_PAGE = 10;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->applicationRepository = new ApplicationRepository(Database::getConnection());
    }

    /**
     * Liste paginée des candidatures, filtrables par promotion et recherche.
     * Les candidatures non traitées ('envoyée') apparaissent en premier dans le tri.
     */
    public function index(): string
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        $selectedPromotionId = isset($_GET['promotion_id']) && ctype_digit((string) $_GET['promotion_id'])
            ? (int) $_GET['promotion_id']
            : null;

        $search = trim((string) ($_GET['q'] ?? ''));

        $currentPage = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0
            ? (int) $_GET['page']
            : 1;

        $promotions = $this->applicationRepository->getActivePromotions();

        $totalApplications = $this->applicationRepository->countApplications($selectedPromotionId, $search);

        $totalPages = max(1, (int) ceil($totalApplications / self::PER_PAGE));

        if ($currentPage > $totalPages) {
            $currentPage = $totalPages;
        }

        $offset = ($currentPage - 1) * self::PER_PAGE;

        $applications = $this->applicationRepository->findApplicationsPaginated(
            $selectedPromotionId,
            $search,
            self::PER_PAGE,
            $offset
        );

        return $this->twig->render('pilot-applications.html.twig', [
            'applications' => $applications,
            'promotions' => $promotions,
            'selected_promotion_id' => $selectedPromotionId,
            'search' => $search,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalApplications' => $totalApplications,
        ]);
    }

    /**
     * Met à jour le statut d'une candidature.
     * Seuls 'acceptee' et 'refusee' sont autorisés (whitelist stricte).
     * La mise à jour est atomique : statut candidature + statut étudiant dans une transaction.
     */
    public function updateStatus(int $applicationId): void
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $status = trim((string) ($_POST['status'] ?? ''));

        // Whitelist stricte : seuls ces deux statuts sont acceptables via ce formulaire
        $allowed = ['acceptee', 'refusee'];

        if (!in_array($status, $allowed, true)) {
            http_response_code(400);
            exit('Statut invalide.');
        }

        // La méthode met à jour deux tables en une seule transaction :
        // 1. candidatures.status → nouveau statut
        // 2. student_profiles.status → 'stage_valide' si une candidature est acceptée, sinon 'en_recherche'
        // Si la transaction échoue, les deux tables restent cohérentes (rollback automatique)
        try {
            $this->applicationRepository->updateApplicationStatusAndStudentProfile($applicationId, $status);
        } catch (\Throwable $e) {
            http_response_code(500);
            exit('Erreur lors de la mise à jour.');
        }

        header('Location: /pilot-candidatures');
        exit;
    }
}