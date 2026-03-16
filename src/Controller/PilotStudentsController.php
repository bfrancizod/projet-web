<?php

namespace App\Controller;

use App\Database;
use Twig\Environment;

class PilotStudentsController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(): string
    {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pilote') {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();

        $stmt = $pdo->query("
            SELECT
                u.id,
                u.nom,
                u.prenom,
                u.email,
                sp.formation,
                sp.status,
                sp.last_activity
            FROM users u
            INNER JOIN student_profiles sp ON sp.user_id = u.id
            WHERE u.role = 'etudiant'
            ORDER BY u.nom ASC
        ");

        $students = $stmt->fetchAll();

        return $this->twig->render('pilot-students.html.twig', [
            'site_name' => 'Help Me Stage',
            'user' => $_SESSION['user'],
            'students' => $students
        ]);
    }
}