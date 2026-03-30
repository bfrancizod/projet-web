<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use Twig\Environment;

class AdminPromotionsController
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
                p.id,
                p.label,
                p.academic_year,
                p.is_active,
                COUNT(DISTINCT sp.user_id) AS students_count,
                COUNT(DISTINCT pp.pilot_user_id) AS pilots_count
            FROM promotions p
            LEFT JOIN student_profiles sp ON sp.promotion_id = p.id
            LEFT JOIN pilot_promotions pp ON pp.promotion_id = p.id
            WHERE 1 = 1
        ";

        $params = [];

        if ($search !== '') {
            $sql .= "
                AND (
                    p.label LIKE :search
                    OR p.academic_year LIKE :search
                )
            ";
            $params['search'] = '%' . $search . '%';
        }

        $sql .= "
            GROUP BY p.id, p.label, p.academic_year, p.is_active
            ORDER BY p.academic_year DESC, p.label ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $this->twig->render('admin-promotions.html.twig', [
            'promotions' => $stmt->fetchAll(),
            'search' => $search,
        ]);
    }
}