<?php

/**
 * Point d'entrée unique de l'application (Front Controller pattern).
 */

declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use App\Database;
use App\Security\Csrf;

use App\Controller\ApplyController;
use App\Controller\AuthController;
use App\Controller\CompanyCommentController;
use App\Controller\ContactController;
use App\Controller\CookieConsentController;
use App\Controller\HomeController;
use App\Controller\LegalController;
use App\Controller\OfferController;
use App\Controller\PilotApplicationController;
use App\Controller\PilotDashboardController;
use App\Controller\PilotOfferController;
use App\Controller\PilotStudentController;
use App\Controller\PrivacyController;
use App\Controller\StudentApplicationsController;
use App\Controller\StudentDashboardController;
use App\Controller\StudentWishlistController;
use App\Controller\StatisticsController;
use App\Controller\WishlistController;

use App\Controller\AdminCompanyController;
use App\Controller\AdminDashboardController;
use App\Controller\AdminPilotController;
use App\Controller\AdminPromotionController;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Migration : ajoute la colonne expires_at à password_reset_requests si elle n'existe pas
try {
    $pdo = Database::getConnection();
    $checkColumn = $pdo->query("SHOW COLUMNS FROM password_reset_requests LIKE 'expires_at'");
    if ($checkColumn->rowCount() === 0) {
        $pdo->exec("ALTER TABLE password_reset_requests ADD COLUMN expires_at DATETIME DEFAULT NULL AFTER created_at");
    }
} catch (\Throwable $e) {
    error_log("Migration warning: " . $e->getMessage());
}

// Détection du HTTPS. On vérifie aussi X-Forwarded-Proto car les hébergeurs
// type Railway/Heroku terminent le SSL sur un proxy et transmettent ensuite
// la requête en HTTP interne (sans cet entête, le HTTPS serait vu comme absent).
$https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) === '443')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
);

// Redirection forcée HTTP -> HTTPS (sécurité : aucune page accessible en clair).
// Ignorée en local (localhost / 127.0.0.1) pour ne pas casser le serveur de dev (php -S).
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = (bool) preg_match('#^(localhost|127\.0\.0\.1)(:\d+)?$#', $host);

if (!$https && !$isLocal) {
    // 301 = redirection permanente (le navigateur mémorise et bascule en HTTPS)
    header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

if (!isset($_SESSION['_initiated'])) {
    session_regenerate_id(true);
    $_SESSION['_initiated'] = time();
}

$sessionTimeout = 30 * 60;

if (isset($_SESSION['user'])) {
    if (isset($_SESSION['_last_activity']) && (time() - $_SESSION['_last_activity']) > $sessionTimeout) {
        session_unset();
        session_destroy();
        header('Location: /connexion?raison=expiration');
        exit;
    }

    $_SESSION['_last_activity'] = time();
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; frame-ancestors 'none'");

if ($https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$loader = new FilesystemLoader(__DIR__ . '/../templates');

$twig = new Environment($loader, [
    'cache' => false,
    'autoescape' => 'html',
]);

$showCookieBanner = true;

if (!empty($_SESSION['cookie_consent_set']) || (($_COOKIE['cookie_consent_status'] ?? '') === '1')) {
    $showCookieBanner = false;
} else {
    $cookieConsentToken = $_COOKIE['cookie_consent_token'] ?? null;

    if (is_string($cookieConsentToken) && $cookieConsentToken !== '') {
        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare("
                SELECT id, analytics, marketing
                FROM cookie_consents
                WHERE consent_token = :consent_token
                LIMIT 1
            ");
            $stmt->execute(['consent_token' => $cookieConsentToken]);
            $cookieConsent = $stmt->fetch();

            if ($cookieConsent) {
                $showCookieBanner = false;

                if (isset($_SESSION['user']['id'])) {
                    $stmt = $pdo->prepare("
                        UPDATE cookie_consents
                        SET user_id = :user_id
                        WHERE consent_token = :consent_token
                          AND (user_id IS NULL OR user_id != :user_id)
                    ");
                    $stmt->execute([
                        'user_id' => (int) $_SESSION['user']['id'],
                        'consent_token' => $cookieConsentToken,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $showCookieBanner = true;
        }
    }
}

$twig->addGlobal('current_user', $_SESSION['user'] ?? null);
$twig->addGlobal('csrf_token', Csrf::token());
$twig->addGlobal('show_cookie_banner', $showCookieBanner);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/*

 Routes dynamiques

*/

if ($method === 'GET' && preg_match('#^/offres/([0-9]+)$#', $uri, $matches)) {
    echo (new OfferController($twig))->show((int) $matches[1]);
    exit;
}

if ($method === 'GET' && preg_match('#^/entreprises/([0-9]+)/commentaires$#', $uri, $matches)) {
    echo (new CompanyCommentController($twig))->index((int) $matches[1]);
    exit;
}

if (preg_match('#^/offres/([0-9]+)/postuler$#', $uri, $matches) && in_array($method, ['GET', 'POST'], true)) {
    echo (new ApplyController($twig))->form((int) $matches[1]);
    exit;
}

if ($method === 'POST' && preg_match('#^/offres/([0-9]+)/wishlist/ajouter$#', $uri, $matches)) {
    (new WishlistController($twig))->add((int) $matches[1]);
    exit;
}

if ($method === 'POST' && preg_match('#^/offres/([0-9]+)/wishlist/supprimer$#', $uri, $matches)) {
    (new WishlistController($twig))->remove((int) $matches[1]);
    exit;
}

if ($method === 'POST' && preg_match('#^/pilot-candidature/([0-9]+)/status$#', $uri, $matches)) {
    (new PilotApplicationController($twig))->updateStatus((int) $matches[1]);
    exit;
}

if ($method === 'GET' && preg_match('#^/pilot-etudiants/([0-9]+)$#', $uri, $matches)) {
    echo (new PilotStudentController($twig))->show((int) $matches[1]);
    exit;
}

if ($method === 'POST' && preg_match('#^/pilot-etudiants/([0-9]+)/supprimer$#', $uri, $matches)) {
    (new PilotStudentController($twig))->delete((int) $matches[1]);
    exit;
}

if ($uri === '/pilot-etudiant-create' && in_array($method, ['GET', 'POST'], true)) {
    echo (new PilotStudentController($twig))->create();
    exit;
}

if (preg_match('#^/pilot-etudiants/([0-9]+)/editer$#', $uri, $matches) && in_array($method, ['GET', 'POST'], true)) {
    echo (new PilotStudentController($twig))->edit((int) $matches[1]);
    exit;
}

if ($uri === '/pilot-offre-create' && in_array($method, ['GET', 'POST'], true)) {
    echo (new PilotOfferController($twig))->create();
    exit;
}

if (preg_match('#^/pilot-offres/([0-9]+)/editer$#', $uri, $matches)) {
    echo (new PilotOfferController($twig))->edit((int) $matches[1]);
    exit;
}

if ($method === 'POST' && preg_match('#^/pilot-offres/([0-9]+)/supprimer$#', $uri, $matches)) {
    (new PilotOfferController($twig))->delete((int) $matches[1]);
    exit;
}

if ($uri === '/admin-pilote-create') {
    echo (new AdminPilotController($twig))->create();
    exit;
}

if (preg_match('#^/admin-pilotes/([0-9]+)/editer$#', $uri, $matches)) {
    echo (new AdminPilotController($twig))->edit((int) $matches[1]);
    exit;
}

if ($method === 'POST' && preg_match('#^/admin-pilotes/([0-9]+)/supprimer$#', $uri, $matches)) {
    (new AdminPilotController($twig))->delete((int) $matches[1]);
    exit;
}

if ($uri === '/admin-entreprise-create') {
    echo (new AdminCompanyController($twig))->create();
    exit;
}

if (preg_match('#^/admin-entreprises/([0-9]+)/editer$#', $uri, $matches)) {
    echo (new AdminCompanyController($twig))->edit((int) $matches[1]);
    exit;
}

if ($method === 'POST' && preg_match('#^/admin-entreprises/([0-9]+)/supprimer$#', $uri, $matches)) {
    (new AdminCompanyController($twig))->delete((int) $matches[1]);
    exit;
}

if ($uri === '/admin-promotion-create') {
    echo (new AdminPromotionController($twig))->create();
    exit;
}

if (preg_match('#^/admin-promotions/([0-9]+)/editer$#', $uri, $matches)) {
    echo (new AdminPromotionController($twig))->edit((int) $matches[1]);
    exit;
}

if ($method === 'POST' && preg_match('#^/admin-promotions/([0-9]+)/supprimer$#', $uri, $matches)) {
    (new AdminPromotionController($twig))->delete((int) $matches[1]);
    exit;
}

/*

 Route dynamique ajoutée : changement de mot de passe par l'admin

*/

if ($method === 'POST' && preg_match('#^/admin-demandes-mdp/([0-9]+)/changer$#', $uri, $matches)) {
    (new AuthController($twig))->adminChangePassword((int) $matches[1]);
    exit;
}

if ($method === 'POST' && preg_match('#^/admin-demandes-mdp/([0-9]+)/supprimer$#', $uri, $matches)) {
    (new AuthController($twig))->adminDeletePasswordRequest((int) $matches[1]);
    exit;
}

/*

 Routes statiques

*/

switch ($uri) {
    case '/':
        echo (new HomeController($twig))->index();
        exit;

    case '/offres':
        echo (new OfferController($twig))->index();
        exit;

    case '/connexion':
        echo (new AuthController($twig))->login();
        exit;

    case '/mot-de-passe-oublie':
        echo (new AuthController($twig))->forgotPassword();
        exit;

    case '/logout':
        (new AuthController($twig))->logout();
        exit;

    case '/espace-etudiant':
        echo (new StudentDashboardController($twig))->index();
        exit;

    case '/etudiant-candidatures':
        echo (new StudentApplicationsController($twig))->index();
        exit;

    case '/etudiant-wishlist':
        echo (new StudentWishlistController($twig))->index();
        exit;

    case '/statistiques-offres':
        echo (new StatisticsController($twig))->index();
        exit;

    case '/espace-pilote':
        echo (new PilotDashboardController($twig))->index();
        exit;

    case '/pilot-etudiants':
        echo (new PilotStudentController($twig))->index();
        exit;

    case '/pilot-offres':
        echo (new PilotOfferController($twig))->index();
        exit;

    case '/pilot-candidatures':
        echo (new PilotApplicationController($twig))->index();
        exit;

    case '/mentions-legales':
        echo (new LegalController($twig))->index();
        exit;

    case '/contact':
        echo (new ContactController($twig))->index();
        exit;

    case '/politique-confidentialite':
        echo (new PrivacyController($twig))->index();
        exit;

    case '/espace-admin':
        echo (new AdminDashboardController($twig))->index();
        exit;

    case '/admin-demandes-mdp':
        echo (new AuthController($twig))->adminPasswordRequests();
        exit;

    case '/admin-pilotes':
        echo (new AdminPilotController($twig))->index();
        exit;

    case '/admin-promotions':
        echo (new AdminPromotionController($twig))->index();
        exit;

    case '/admin-entreprises':
        echo (new AdminCompanyController($twig))->index();
        exit;

    case '/admin-offres':
        echo (new PilotOfferController($twig))->index();
        exit;

    case '/admin-etudiants':
        echo (new PilotStudentController($twig))->index();
        exit;

    default:
        http_response_code(404);
        echo 'Page non trouvée';
        exit;
}