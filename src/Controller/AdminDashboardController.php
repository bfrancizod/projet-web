<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\DashboardRepository;
use Twig\Environment;

class AdminDashboardController
{
    private Environment $twig;
    private DashboardRepository $dashboardRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->dashboardRepository = new DashboardRepository(Database::getConnection());
    }

    public function index(): string
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'administrateur') {
            header('Location: /connexion');
            exit;
        }

        return $this->twig->render('admin-dashboard.html.twig', [
            'offers_count' => $this->dashboardRepository->countOffers(),
            'students_count' => $this->dashboardRepository->countStudents(),
            'pilots_count' => $this->dashboardRepository->countPilots(),
            'applications_count' => $this->dashboardRepository->countApplications(),
            'promotions_count' => $this->dashboardRepository->countPromotions(),
            'recent_students' => $this->dashboardRepository->findRecentStudents(5),
        ]);
    }
}