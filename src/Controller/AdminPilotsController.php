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

        $search = trim((string) ($_GET['q'] ?? ''));

        $sql = "
            SELECT
                id,
                nom,
                prenom,
                email,
                created_at
            FROM users
            WHERE role = 'pilote'
        ";

        $params = [];

        if ($search !== '') {
            $sql .= "
                AND (
                    nom LIKE :search
                    OR prenom LIKE :search
                    OR email LIKE :search
                    OR CONCAT(prenom, ' ', nom) LIKE :search
                    OR CONCAT(nom, ' ', prenom) LIKE :search
                )
            ";
            $params['search'] = '%' . $search . '%';
        }

        $sql .= " ORDER BY nom ASC, prenom ASC ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pilots = $stmt->fetchAll();

        return $this->twig->render('admin-pilots.html.twig', [
            'pilots' => $pilots,
            'search' => $search,
        ]);
    }
}