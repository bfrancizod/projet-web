<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;

class CookieConsentController
{
    public function save(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit('Méthode non autorisée.');
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $action = (string) ($_POST['cookie_action'] ?? 'save_preferences');

        $essential = 1;
        $analytics = 0;
        $marketing = 0;

        if ($action === 'accept_all') {
            $analytics = 1;
            $marketing = 1;
        } elseif ($action === 'reject_all') {
            $analytics = 0;
            $marketing = 0;
        } else {
            $analytics = isset($_POST['analytics']) ? 1 : 0;
            $marketing = isset($_POST['marketing']) ? 1 : 0;
        }

        $token = $_COOKIE['cookie_consent_token'] ?? bin2hex(random_bytes(32));
        $userId = $_SESSION['user']['id'] ?? null;

        $https = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) === '443')
        );

        setcookie('cookie_consent_token', $token, [
            'expires' => time() + (365 * 24 * 60 * 60),
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            INSERT INTO cookie_consents (
                consent_token,
                user_id,
                essential,
                analytics,
                marketing
            )
            VALUES (
                :consent_token,
                :user_id,
                :essential,
                :analytics,
                :marketing
            )
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                essential = VALUES(essential),
                analytics = VALUES(analytics),
                marketing = VALUES(marketing),
                updated_at = current_timestamp()
        ");

        $stmt->execute([
            'consent_token' => $token,
            'user_id' => $userId,
            'essential' => $essential,
            'analytics' => $analytics,
            'marketing' => $marketing,
        ]);

        $_SESSION['cookie_consent_set'] = true;

        $redirect = $_SERVER['HTTP_REFERER'] ?? '/';
        header('Location: ' . $redirect);
        exit;
    }
}