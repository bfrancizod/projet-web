<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\CookieConsentRepository;

/**
 * Contrôleur AJAX de gestion du consentement aux cookies (RGPD)
 *
 * Point d'entrée JSON — ne rend pas de template Twig.
 * Appelé depuis le banner cookie en JavaScript via fetch().
 * Ce contrôleur n'injecte pas Twig car il ne renvoie que du JSON.
 */
class CookieConsentController
{
    private CookieConsentRepository $cookieConsentRepository;

    public function __construct()
    {
        $this->cookieConsentRepository = new CookieConsentRepository(Database::getConnection());
    }

    /**
     * Enregistre les choix de consentement de l'utilisateur.
     *
     * Reçoit un JSON via php://input (pas $_POST car Content-Type: application/json).
     * Les cookies essentiels sont toujours true — non modifiable par l'utilisateur.
     * Retourne un JSON {success: bool} avec le code HTTP approprié.
     */
    public function save(): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'message' => 'Méthode non autorisée.',
            ]);
            return;
        }

        // fetch() envoie du JSON en Content-Type: application/json, donc le corps n'est PAS
        // dans $_POST (qui ne lit que application/x-www-form-urlencoded et multipart/form-data).
        // php://input est le flux brut du corps de la requête HTTP.
        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody ?: '', true);

        // json_decode retourne null si le JSON est malformé → is_array() filtre ce cas
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Payload invalide.',
            ]);
            return;
        }

        $consentToken = trim((string) ($data['consent_token'] ?? ''));
        $essential = true; // Les cookies essentiels sont toujours acceptés (fonctionnement du site)
        $analytics = (bool) ($data['analytics'] ?? false);
        $marketing = (bool) ($data['marketing'] ?? false);

        if ($consentToken === '') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Token de consentement manquant.',
            ]);
            return;
        }

        try {
            $this->cookieConsentRepository->saveConsent(
                $consentToken,
                $essential,
                $analytics,
                $marketing
            );

            echo json_encode([
                'success' => true,
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors de l’enregistrement du consentement.',
            ]);
        }
    }
}