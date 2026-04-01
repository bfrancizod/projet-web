<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\ApplicationRepository;
use App\Security\Csrf;
use Twig\Environment;

class ApplyController
{
    private Environment $twig;
    private ApplicationRepository $applicationRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->applicationRepository = new ApplicationRepository(Database::getConnection());
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

        $userId = (int) $_SESSION['user']['id'];

        $offer = $this->applicationRepository->findOfferById($offerId);

        if (!$offer) {
            http_response_code(404);
            return 'Offre introuvable.';
        }

        $alreadyApplied = $this->applicationRepository->hasStudentAppliedToOffer($userId, $offerId);
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

            if ($alreadyApplied) {
                $error = 'Vous avez déjà postulé à cette offre.';
            } else {
                $lettreMotivation = trim((string) ($_POST['lettre_motivation'] ?? ''));

                if ($lettreMotivation === '' || mb_strlen($lettreMotivation) < 20) {
                    $error = 'La lettre de motivation doit contenir au moins 20 caractères.';
                } elseif (!isset($_FILES['cv']) || $_FILES['cv']['error'] !== UPLOAD_ERR_OK) {
                    $error = 'Erreur lors de l\'upload du CV.';
                } else {
                    $file = $_FILES['cv'];

                    if ($file['size'] > 2 * 1024 * 1024) {
                        $error = 'Le CV ne doit pas dépasser 2 Mo.';
                    } else {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mimeType = finfo_file($finfo, $file['tmp_name']);
                        finfo_close($finfo);

                        if ($mimeType !== 'application/pdf') {
                            $error = 'Le fichier doit être un PDF.';
                        } else {
                            $uploadDir = __DIR__ . '/../../public/uploads/cv/';
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0777, true);
                            }

                            $prenom = (string) ($_SESSION['user']['prenom'] ?? 'etudiant');
                            $nom = (string) ($_SESSION['user']['nom'] ?? 'inconnu');
                            $offreTitre = (string) ($offer['titre'] ?? 'offre');

                            $sanitize = static function (string $value): string {
                                $value = trim($value);
                                $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
                                $value = strtolower($value);
                                $value = preg_replace('/[^a-z0-9]+/', '_', $value);
                                $value = trim($value, '_');

                                return $value !== '' ? $value : 'inconnu';
                            };

                            $prenomSafe = $sanitize($prenom);
                            $nomSafe = $sanitize($nom);
                            $offreSafe = substr($sanitize($offreTitre), 0, 50);
                            $timestamp = date('Ymd_His');

                            $filename = $prenomSafe . '_' . $nomSafe . '_' . $offreSafe . '_' . $timestamp . '.pdf';
                            $destination = $uploadDir . $filename;

                            if (move_uploaded_file($file['tmp_name'], $destination)) {
                                try {
                                    $this->applicationRepository->createApplication(
                                        $userId,
                                        $offerId,
                                        $lettreMotivation,
                                        $filename
                                    );

                                    header('Location: /espace-etudiant?success=application_sent');
                                    exit;
                                } catch (\Throwable $e) {
                                    $error = 'Erreur lors de l’enregistrement de la candidature.';
                                }
                            } else {
                                $error = 'Impossible de sauvegarder le fichier.';
                            }
                        }
                    }
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