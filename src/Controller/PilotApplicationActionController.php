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
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
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

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE candidatures
                SET status = :status
                WHERE id = :id
            ");
            $stmt->execute([
                'status' => $status,
                'id' => $applicationId,
            ]);

            $stmt = $pdo->prepare("
                SELECT student_user_id
                FROM candidatures
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $applicationId]);
            $studentUserId = $stmt->fetchColumn();

            if ($studentUserId) {
                $stmt = $pdo->prepare("
                    SELECT
                        MAX(CASE WHEN status = 'acceptee' THEN 1 ELSE 0 END) AS has_accepted,
                        MAX(CASE WHEN status = 'en_etude' THEN 1 ELSE 0 END) AS has_in_progress,
                        MAX(CASE WHEN status = 'envoyee' THEN 1 ELSE 0 END) AS has_sent
                    FROM candidatures
                    WHERE student_user_id = :student_user_id
                ");
                $stmt->execute(['student_user_id' => $studentUserId]);
                $summary = $stmt->fetch();

                $newStudentStatus = 'sans_stage';

                if ((int) ($summary['has_accepted'] ?? 0) === 1) {
                    $newStudentStatus = 'stage_valide';
                } elseif ((int) ($summary['has_in_progress'] ?? 0) === 1) {
                    $newStudentStatus = 'stage_trouve';
                } elseif ((int) ($summary['has_sent'] ?? 0) === 1) {
                    $newStudentStatus = 'en_recherche';
                }

                $stmt = $pdo->prepare("
                    UPDATE student_profiles
                    SET status = :status,
                        last_activity = CURDATE()
                    WHERE user_id = :user_id
                ");
                $stmt->execute([
                    'status' => $newStudentStatus,
                    'user_id' => $studentUserId,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            http_response_code(500);
            exit('Erreur lors de la mise à jour.');
        }

        header('Location: /pilot-candidatures');
        exit;
    }
}