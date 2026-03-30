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

        // Désormais seuls ces 2 statuts sont autorisés
        $allowed = ['acceptee', 'refusee'];

        if (!in_array($status, $allowed, true)) {
            http_response_code(400);
            exit('Statut invalide.');
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            // Mise à jour du statut de la candidature
            $stmt = $pdo->prepare("
                UPDATE candidatures
                SET status = :status
                WHERE id = :id
            ");
            $stmt->execute([
                'status' => $status,
                'id' => $applicationId,
            ]);

            // Récupération de l'étudiant concerné
            $stmt = $pdo->prepare("
                SELECT student_user_id
                FROM candidatures
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $applicationId]);
            $studentUserId = $stmt->fetchColumn();

            if ($studentUserId) {
                // Vérifie si l'étudiant a au moins une candidature acceptée
                $stmt = $pdo->prepare("
                    SELECT
                        MAX(CASE WHEN status = 'acceptee' THEN 1 ELSE 0 END) AS has_accepted
                    FROM candidatures
                    WHERE student_user_id = :student_user_id
                ");
                $stmt->execute(['student_user_id' => $studentUserId]);
                $summary = $stmt->fetch();

                // Si une candidature est acceptée => stage validé
                // Sinon => toujours en recherche
                $newStudentStatus = ((int) ($summary['has_accepted'] ?? 0) === 1)
                    ? 'stage_valide'
                    : 'en_recherche';

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