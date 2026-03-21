<?php

declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use App\Controller\ApplyController;
use App\Controller\AuthController;
use App\Controller\ContactController;
use App\Controller\CookieConsentController;
use App\Controller\HomeController;
use App\Controller\LegalController;
use App\Controller\OfferController;
use App\Controller\OfferDetailController;
use App\Controller\PilotApplicationActionController;
use App\Controller\PilotApplicationsController;
use App\Controller\PilotDashboardController;
use App\Controller\PilotOffersController;
use App\Controller\PilotStudentDeleteController;
use App\Controller\PilotStudentDetailController;
use App\Controller\PilotStudentFormController;
use App\Controller\PilotStudentsController;
use App\Controller\PrivacyController;
use App\Controller\StudentApplicationsController;
use App\Controller\StudentDashboardController;
use App\Controller\StudentWishlistController;
use App\Controller\WishlistController;
use App\Database;
use App\Security\Csrf;
use App\Controller\AdminDashboardController;
use App\Controller\AdminPilotsController;
use App\Controller\AdminPilotFormController;
use App\Controller\AdminPilotDeleteController;
use App\Controller\AdminCompaniesController;
use App\Controller\AdminCompanyFormController;
use App\Controller\AdminCompanyDeleteController;
use App\Controller\PilotOfferFormController;
use App\Controller\PilotOfferDeleteController;

$https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) === '443')
);

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

require_once __DIR__ . '/../vendor/autoload.php';

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

$loader = new FilesystemLoader(__DIR__ . '/../templates');

$twig = new Environment($loader, [
    'cache' => false,
    'autoescape' => 'html',
]);

/*
|--------------------------------------------------------------------------
| Gestion du bandeau cookies
|--------------------------------------------------------------------------
*/
$showCookieBanner = true;
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

$twig->addGlobal('current_user', $_SESSION['user'] ?? null);
$twig->addGlobal('csrf_token', Csrf::token());
$twig->addGlobal('show_cookie_banner', $showCookieBanner);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/*
|--------------------------------------------------------------------------
| Routes dynamiques
|--------------------------------------------------------------------------
*/

// Détail offre
if ($method === 'GET' && preg_match('#^/offres/([0-9]+)$#', $uri, $matches)) {
    echo (new OfferDetailController($twig))->show((int) $matches[1]);
    exit;
}

// Postuler à une offre
if (preg_match('#^/offres/([0-9]+)/postuler$#', $uri, $matches) && in_array($method, ['GET', 'POST'], true)) {
    echo (new ApplyController($twig))->form((int) $matches[1]);
    exit;
}

// Wishlist ajouter
if ($method === 'POST' && preg_match('#^/offres/([0-9]+)/wishlist/ajouter$#', $uri, $matches)) {
    (new WishlistController($twig))->add((int) $matches[1]);
    exit;
}

// Wishlist supprimer
if ($method === 'POST' && preg_match('#^/offres/([0-9]+)/wishlist/supprimer$#', $uri, $matches)) {
    (new WishlistController($twig))->remove((int) $matches[1]);
    exit;
}

// Mise à jour statut candidature côté pilote
if ($method === 'POST' && preg_match('#^/pilot-candidature/([0-9]+)/status$#', $uri, $matches)) {
    (new PilotApplicationActionController($twig))->updateStatus((int) $matches[1]);
    exit;
}

// Détail étudiant côté pilote
if ($method === 'GET' && preg_match('#^/pilot-etudiants/([0-9]+)$#', $uri, $matches)) {
    echo (new PilotStudentDetailController($twig))->show((int) $matches[1]);
    exit;
}

// Suppression étudiant côté pilote
if ($method === 'POST' && preg_match('#^/pilot-etudiants/([0-9]+)/supprimer$#', $uri, $matches)) {
    (new PilotStudentDeleteController($twig))->delete((int) $matches[1]);
    exit;
}

// Création étudiant
if ($uri === '/pilot-etudiant-create' && in_array($method, ['GET', 'POST'], true)) {
    echo (new PilotStudentFormController($twig))->create();
    exit;
}

// Édition étudiant
if (preg_match('#^/pilot-etudiants/([0-9]+)/editer$#', $uri, $matches) && in_array($method, ['GET', 'POST'], true)) {
    echo (new PilotStudentFormController($twig))->edit((int) $matches[1]);
    exit;
}

// Consentement cookies
if ($uri === '/cookies/consent' && $method === 'POST') {
    (new CookieConsentController())->save();
    exit;
}

if ($uri === '/admin-pilote-create' && in_array($method, ['GET', 'POST'], true)) {
    echo (new AdminPilotFormController($twig))->create();
    exit;
}

if (preg_match('#^/admin-pilotes/([0-9]+)/editer$#', $uri, $matches) && in_array($method, ['GET', 'POST'], true)) {
    echo (new AdminPilotFormController($twig))->edit((int) $matches[1]);
    exit;
}

if ($method === 'POST' && preg_match('#^/admin-pilotes/([0-9]+)/supprimer$#', $uri, $matches)) {
    (new AdminPilotDeleteController())->delete((int) $matches[1]);
    exit;
}

if ($uri === '/admin-entreprise-create' && in_array($method, ['GET', 'POST'], true)) {
    echo (new AdminCompanyFormController($twig))->create();
    exit;
}

if (preg_match('#^/admin-entreprises/([0-9]+)/editer$#', $uri, $matches) && in_array($method, ['GET', 'POST'], true)) {
    echo (new AdminCompanyFormController($twig))->edit((int) $matches[1]);
    exit;
}

if ($method === 'POST' && preg_match('#^/admin-entreprises/([0-9]+)/supprimer$#', $uri, $matches)) {
    (new AdminCompanyDeleteController())->delete((int) $matches[1]);
    exit;
}
if ($uri === '/pilot-offre-create' && in_array($method, ['GET', 'POST'], true)) {
    echo (new PilotOfferFormController($twig))->create();
    exit;
}

if (preg_match('#^/pilot-offres/([0-9]+)/editer$#', $uri, $matches) && in_array($method, ['GET', 'POST'], true)) {
    echo (new PilotOfferFormController($twig))->edit((int) $matches[1]);
    exit;
}

if ($method === 'POST' && preg_match('#^/pilot-offres/([0-9]+)/supprimer$#', $uri, $matches)) {
    (new PilotOfferDeleteController())->delete((int) $matches[1]);
    exit;
}
/*
|--------------------------------------------------------------------------
| Routes statiques
|--------------------------------------------------------------------------
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

    case '/espace-pilote':
        echo (new PilotDashboardController($twig))->index();
        exit;

    case '/pilot-etudiants':
        echo (new PilotStudentsController($twig))->index();
        exit;

    case '/pilot-offres':
        echo (new PilotOffersController($twig))->index();
        exit;

    case '/pilot-candidatures':
        echo (new PilotApplicationsController($twig))->index();
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

    case '/admin-pilotes':
        echo (new AdminPilotsController($twig))->index();
        exit;

    case '/admin-entreprises':
        echo (new AdminCompaniesController($twig))->index();
        exit;

    default:
        http_response_code(404);
        echo 'Page non trouvée';
        exit;

}