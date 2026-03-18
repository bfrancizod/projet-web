<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use Twig\Environment;

class AdminPilotsController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(): string
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'administrateur') {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();

        $stmt = $pdo->query("
            SELECT
                id,
                nom,
                prenom,
                email,
                created_at
            FROM users
            WHERE role = 'pilote'
            ORDER BY nom ASC, prenom ASC
        ");

        $pilots = $stmt->fetchAll();

        return $this->twig->render('admin-pilots.html.twig', [
            'pilots' => $pilots,
        ]);
    }
}