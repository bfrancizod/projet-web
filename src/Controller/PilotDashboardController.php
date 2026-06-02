<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\DashboardRepository;
use App\Support\PilotPromotionAccess;
use Twig\Environment;

/**
 * Contrôleur du tableau de bord pilote
 *
 * Accessible uniquement aux pilotes connectés.
 * Toutes les statistiques sont restreintes aux promotions assignées au pilote connecté
 * via PilotPromotionAccess::getAssignedPromotionIds().
 */
class PilotDashboardController
{
    private Environment $twig;
    private DashboardRepository $dashboardRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->dashboardRepository = new DashboardRepository(Database::getConnection());
    }

    /**
     * Affiche le dashboard pilote avec les statistiques de ses promotions.
     * Les offres (offersCount) sont globales — un pilote voit toutes les offres
     * car elles sont communes à toute la plateforme.
     */
    public function index(): string
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'pilote') {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();
        $pilotId = (int) ($_SESSION['user']['id'] ?? 0);

        // Récupère les IDs des promotions auxquelles ce pilote est rattaché
        $allowedPromotionIds = PilotPromotionAccess::getAssignedPromotionIds($pdo, $pilotId);

        $totalStudents = $this->dashboardRepository->countPilotStudents($allowedPromotionIds);
        $studentsWithoutStage = $this->dashboardRepository->countPilotStudentsWithoutStage($allowedPromotionIds);
        $offersCount = $this->dashboardRepository->countOffers();
        $applicationsCount = $this->dashboardRepository->countPilotApplications($allowedPromotionIds);
        $validatedStages = $this->dashboardRepository->countPilotValidatedStages($allowedPromotionIds);
        $recentStudents = $this->dashboardRepository->findRecentPilotStudents($allowedPromotionIds, 5);

        return $this->twig->render('pilot-dashboard.html.twig', [
            'total_students' => $totalStudents,
            'students_without_stage' => $studentsWithoutStage,
            'offers_count' => $offersCount,
            'applications_count' => $applicationsCount,
            'validated_stages' => $validatedStages,
            'recent_students' => $recentStudents,
        ]);
    }
}