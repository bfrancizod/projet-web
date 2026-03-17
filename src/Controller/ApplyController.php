<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;
use Twig\Environment;

class ApplyController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function form(int $offerId): string
    {
        if (!isset($_SESSION['user'])) {
            header('Location: /connexion');
            exit;
        }

        if (($_SESSION['user']['role'] ?? null) !== 'etudiant') {
            http_response_code(403);
            return 'Seuls les étudiants peuvent postuler à cette offre.';
        }

        $pdo = Database::getConnection();
        $userId = (int) $_SESSION['user']['id'];

        $stmt = $pdo->prepare("
            SELECT *
            FROM offres
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $offerId]);
        $offer = $stmt->fetch();

        if (!$offer) {
            http_response_code(404);
            return 'Offre introuvable.';
        }

        $stmt = $pdo->prepare("
            SELECT id
            FROM candidatures
            WHERE student_user_id = :user_id
              AND offre_id = :offer_id
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'offer_id' => $offerId,
        ]);

        $alreadyApplied = (bool) $stmt->fetchColumn();
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

            if ($alreadyApplied) {
                $error = 'Vous avez déjà postulé à cette offre.';
            } else {
                $lettreMotivation = trim((string) ($_POST['lettre_motivation'] ?? ''));
                $cvFilename = trim((string) ($_POST['cv_filename'] ?? ''));

                if ($lettreMotivation === '' || mb_strlen($lettreMotivation) < 20) {
                    $error = 'La lettre de motivation doit contenir au moins 20 caractères.';
                } elseif ($cvFilename === '') {
                    $error = 'Le nom du CV est requis.';
                } elseif (mb_strlen($cvFilename) > 255) {
                    $error = 'Nom de CV trop long.';
                } elseif (!preg_match('/^[a-zA-Z0-9._-]+\.pdf$/', $cvFilename)) {
                    $error = 'Le CV doit être un fichier PDF valide.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO candidatures (
                            student_user_id,
                            offre_id,
                            status,
                            lettre_motivation,
                            cv_filename
                        )
                        VALUES (
                            :user_id,
                            :offer_id,
                            'envoyee',
                            :lettre_motivation,
                            :cv_filename
                        )
                    ");

                    $stmt->execute([
                        'user_id' => $userId,
                        'offer_id' => $offerId,
                        'lettre_motivation' => $lettreMotivation,
                        'cv_filename' => $cvFilename,
                    ]);

                    header('Location: /espace-etudiant?success=application_sent');
                    exit;
                }
            }
        }

        return $this->twig->render('apply.html.twig', [
            'site_name' => 'Help Me Stage',
            'offer' => $offer,
            'already_applied' => $alreadyApplied,
            'error' => $error,
        ]);
    }
}