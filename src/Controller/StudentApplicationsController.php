<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\ApplicationRepository;
use Twig\Environment;

/**
 * Contrôleur de la liste des candidatures de l'étudiant connecté
 *
 * Accessible uniquement aux étudiants.
 * Affiche toutes leurs candidatures avec le statut et les détails de l'offre.
 */
class StudentApplicationsController
{
    private Environment $twig;
    private ApplicationRepository $applicationRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->applicationRepository = new ApplicationRepository(Database::getConnection());
    }

    /** Affiche toutes les candidatures de l'étudiant connecté, triées par date décroissante */
    public function index(): string
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'etudiant') {
            header('Location: /connexion');
            exit;
        }

        $userId = (int) $_SESSION['user']['id'];

        $applications = $this->applicationRepository->findApplicationsByStudentUserId($userId);

        return $this->twig->render('student-applications.html.twig', [
            'site_name' => 'Help Me Stage',
            'applications' => $applications,
        ]);
    }
}