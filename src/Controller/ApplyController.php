<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\ApplicationRepository;
use App\Security\Csrf;
use Twig\Environment;

/**
 * Contrôleur de candidature à une offre de stage
 *
 * Accessible uniquement aux étudiants connectés.
 * Gère l'upload sécurisé du CV (PDF, 2 Mo max, quota 20 fichiers par étudiant)
 * et l'enregistrement de la lettre de motivation.
 */
class ApplyController
{
    private Environment $twig;
    private ApplicationRepository $applicationRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->applicationRepository = new ApplicationRepository(Database::getConnection());
    }

    /**
     * Affiche et traite le formulaire de candidature pour une offre.
     *
     * Vérifications dans l'ordre :
     * 1. Utilisateur connecté et rôle étudiant
     * 2. Offre existante
     * 3. Pas de doublon de candidature
     * 4. Lettre de motivation (20 à 5000 caractères)
     * 5. Fichier CV présent sans erreur d'upload
     * 6. Taille ≤ 2 Mo
     * 7. MIME type = application/pdf (vérifié via finfo, pas l'extension)
     * 8. Quota ≤ 20 CVs par étudiant
     */
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
                } elseif (mb_strlen($lettreMotivation) > 5000) {
                    $error = 'La lettre de motivation ne doit pas dépasser 5000 caractères.';
                } elseif (!isset($_FILES['cv']) || $_FILES['cv']['error'] !== UPLOAD_ERR_OK) {
                    $error = 'Erreur lors de l\'upload du CV.';
                } else {
                    $file = $_FILES['cv'];

                    if ($file['size'] > 2 * 1024 * 1024) {
                        $error = 'Le CV ne doit pas dépasser 2 Mo.';
                    } else {
                        // finfo lit les magic bytes du fichier (pas l'extension).
                        // Un utilisateur malveillant pourrait renommer un .exe en .pdf :
                        // vérifier l'extension serait insuffisant, finfo détecte le vrai type.
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mimeType = finfo_file($finfo, $file['tmp_name']);
                        finfo_close($finfo);

                        if ($mimeType !== 'application/pdf') {
                            $error = 'Le fichier doit être un PDF.';
                        } else {
                            $uploadDir = __DIR__ . '/../../storage/cv/';

                            // Crée le dossier si absent (premier upload). 0775 = rwxrwxr-x,
                            // true = récursif (crée les dossiers parents si nécessaire)
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0775, true);
                            }

                            $prenom = (string) ($_SESSION['user']['prenom'] ?? 'etudiant');
                            $nom = (string) ($_SESSION['user']['nom'] ?? 'inconnu');
                            $offreTitre = (string) ($offer['titre'] ?? 'offre');

                            // Sanitize le nom de fichier pour éviter le path traversal et les caractères invalides.
                        // iconv('UTF-8', 'ASCII//TRANSLIT') convertit "é" → "e", "ç" → "c", etc.
                        // IGNORE supprime les caractères non translittérables plutôt que d'échouer.
                        // Le trim final des underscores évite des noms comme "_jean_" ou "__".
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

                            // Quota anti-DoS : limite à 20 CVs par étudiant pour éviter l'épuisement du disque.
                            // glob() liste tous les fichiers correspondant au pattern "prenom_nom_*.pdf",
                            // ce qui correspond aux CVs de cet étudiant (le timestamp varie, d'où le wildcard).
                            // ?: [] protège contre le cas où glob() retourne false (dossier inexistant).
                            $maxCvs = 20;
                            $existingCvs = glob($uploadDir . $prenomSafe . '_' . $nomSafe . '_*.pdf') ?: [];
                            if (count($existingCvs) >= $maxCvs) {
                                $error = 'Vous avez atteint la limite de ' . $maxCvs . ' CV uploadés.';
                            }

                            $filename = $prenomSafe . '_' . $nomSafe . '_' . $offreSafe . '_' . $timestamp . '.pdf';
                            $destination = $uploadDir . $filename;

                            if ($error === null && move_uploaded_file($file['tmp_name'], $destination)) {
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