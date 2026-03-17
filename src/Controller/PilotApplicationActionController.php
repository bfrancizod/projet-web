<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;
use Twig\Environment;

class PilotApplicationActionController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function updateStatus(int $applicationId): void
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'pilote') {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $status = trim((string) ($_POST['status'] ?? ''));
        $allowed = ['envoyee', 'en_etude', 'acceptee', 'refusee'];

        if (!in_array($status, $allowed, true)) {
            http_response_code(400);
            exit('Statut invalide.');
        }

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            UPDATE candidatures
            SET status = :status
            WHERE id = :id
        ");
        $stmt->execute([
            'status' => $status,
            'id' => $applicationId,
        ]);

        header('Location: /pilot-candidatures');
        exit;
    }
}