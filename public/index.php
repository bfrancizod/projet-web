<?php

/**
 * Point d'entrée unique de l'application (Front Controller pattern).
 *
 * Toutes les requêtes HTTP sont redirigées ici par .htaccess via mod_rewrite.
 * Ce fichier orchestre dans l'ordre :
 *  1. Autoloading Composer + variables d'environnement (.env)
 *  2. Sécurité session (cookie sécurisé, régénération d'ID, timeout 30 min)
 *  3. En-têtes de sécurité HTTP (CSP, HSTS, X-Frame-Options…)
 *  4. Initialisation Twig (templates + autoescape HTML)
 *  5. Gestion du consentement cookies (RGPD)
 *  6. Routage : routes dynamiques (regex) puis routes statiques (switch)
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

// Chargement de l'autoloader Composer (toutes les classes src/ + vendor/)
require_once __DIR__ . '/../vendor/autoload.php';

// Chargement des variables d'environnement depuis le fichier .env (non versionné)
// createImmutable() interdit la surcharge des variables déjà définies dans l'environnement système
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Détection HTTPS : prend en compte les proxies inverses (ex: load balancer Railway)
// qui peuvent forwarder la requête en HTTP tout en ayant reçu du HTTPS
$https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) === '443')
);

// Configuration du cookie de session AVANT session_start()
// - lifetime=0 : cookie de session (disparaît à la fermeture du navigateur)
// - secure=true uniquement en HTTPS pour éviter la transmission en clair
// - httponly=true : inaccessible depuis JavaScript (protection XSS)
// - samesite=Lax : protège contre le CSRF inter-domaines tout en autorisant les liens entrants
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

// Régénération de l'ID de session à la première visite pour prévenir la fixation de session :
// un attaquant ne peut pas forcer un ID connu avant l'authentification
if (!isset($_SESSION['_initiated'])) {
    session_regenerate_id(true);
    $_SESSION['_initiated'] = time();
}

// Déconnexion automatique après 30 minutes d'inactivité
// _last_activity est mis à jour à chaque requête ; si le délai est dépassé on détruit la session
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

// En-têtes de sécurité HTTP envoyés sur toutes les réponses
// X-Frame-Options : bloque le clickjacking (embedding dans une iframe tierce)
header('X-Frame-Options: SAMEORIGIN');
// X-Content-Type-Options : empêche le MIME sniffing (navigateur respecte le Content-Type déclaré)
header('X-Content-Type-Options: nosniff');
// Referrer-Policy : transmet l'origine seulement, pas l'URL complète lors des navigations cross-origin
header('Referrer-Policy: strict-origin-when-cross-origin');
// Permissions-Policy : désactive les API sensibles du navigateur non utilisées par le site
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
// Content-Security-Policy : restreint les sources autorisées pour chaque type de ressource
// 'unsafe-inline' est requis pour les styles/scripts inline existants (à supprimer idéalement)
// frame-ancestors 'none' renforce X-Frame-Options pour les navigateurs modernes
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; frame-ancestors 'none'");
// HSTS : force HTTPS pendant 1 an, envoyé uniquement si la connexion est déjà en HTTPS
// (sinon le header serait ignoré ou contre-productif)
if ($https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Initialisation de Twig
// - FilesystemLoader pointe sur le dossier templates/ à la racine du projet
// - cache=false : pas de mise en cache des templates (à activer en production avec un dossier var/cache)
// - autoescape='html' : échappe automatiquement toutes les variables dans les templates Twig
//   (protection XSS par défaut — utiliser |raw uniquement pour du HTML de confiance)
$loader = new FilesystemLoader(__DIR__ . '/../templates');

$twig = new Environment($loader, [
    'cache' => false,
    'autoescape' => 'html',
]);

// --- Gestion du consentement cookies (RGPD) ---
// Le banner est masqué si : l'utilisateur a déjà consenti dans cette session
// OU si un cookie de statut est présent (défini par le JavaScript du banner)
$showCookieBanner = true;

if (!empty($_SESSION['cookie_consent_set']) || (($_COOKIE['cookie_consent_status'] ?? '') === '1')) {
    $showCookieBanner = false;
} else {
    // Vérifie si un token de consentement persistant existe dans les cookies du navigateur
    // Ce token est créé par le JavaScript lors du premier consentement et stocké 1 an
    $cookieConsentToken = $_COOKIE['cookie_consent_token'] ?? null;

    if (is_string($cookieConsentToken) && $cookieConsentToken !== '') {
        try {
            $pdo = Database::getConnection();

            // Recherche du consentement en BDD via le token (identifiant anonyme RGPD)
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

                // Si l'utilisateur est connecté, rattache son consentement anonyme à son compte
                // (uniquement si ce n'est pas déjà fait pour éviter une écriture inutile)
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
            // En cas d'erreur BDD, on affiche le banner par sécurité (fail safe)
            $showCookieBanner = true;
        }
    }
}

// Variables globales Twig : disponibles dans tous les templates sans les passer explicitement
// - current_user : données de l'utilisateur connecté (null si visiteur)
// - csrf_token : token CSRF régénéré à chaque session (protection formulaires)
// - show_cookie_banner : déclenche l'affichage du banner RGPD dans base.html.twig
$twig->addGlobal('current_user', $_SESSION['user'] ?? null);
$twig->addGlobal('csrf_token', Csrf::token());
$twig->addGlobal('show_cookie_banner', $showCookieBanner);

// Extraction du chemin URI sans les paramètres GET (ex: '/offres/42?page=2' → '/offres/42')
// parse_url évite que les query strings ne perturbent le routage
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/*
|--------------------------------------------------------------------------
| Routes dynamiques
|--------------------------------------------------------------------------
| Ces routes contiennent un paramètre numérique dans l'URL (ex: /offres/42).
| preg_match() extrait l'ID dans $matches[1] et le transmet au contrôleur.
| Elles sont évaluées en premier car elles sont plus spécifiques que les routes statiques.
*/

// Détail d'une offre publique : /offres/{id}
if ($method === 'GET' && preg_match('#^/offres/([0-9]+)$#', $uri, $matches)) {
    echo (new OfferController($twig))->show((int) $matches[1]);
    exit;
}

// Liste des commentaires d'une entreprise : /entreprises/{id}/commentaires
if ($method === 'GET' && preg_match('#^/entreprises/([0-9]+)/commentaires$#', $uri, $matches)) {
    echo (new CompanyCommentController($twig))->index((int) $matches[1]);
    exit;
}

// Formulaire de candidature : /offres/{id}/postuler (GET = affichage, POST = soumission)
if (preg_match('#^/offres/([0-9]+)/postuler$#', $uri, $matches) && in_array($method, ['GET', 'POST'], true)) {
    echo (new ApplyController($twig))->form((int) $matches[1]);
    exit;
}

// Wishlist étudiant : actions POST uniquement (ajouter / supprimer une offre de la liste de souhaits)
if ($method === 'POST' && preg_match('#^/offres/([0-9]+)/wishlist/ajouter$#', $uri, $matches)) {
    (new WishlistController($twig))->add((int) $matches[1]);
    exit;
}

if ($method === 'POST' && preg_match('#^/offres/([0-9]+)/wishlist/supprimer$#', $uri, $matches)) {
    (new WishlistController($twig))->remove((int) $matches[1]);
    exit;
}

// Changement de statut d'une candidature (pilote/admin) : POST /pilot-candidature/{id}/status
if ($method === 'POST' && preg_match('#^/pilot-candidature/([0-9]+)/status$#', $uri, $matches)) {
    (new PilotApplicationController($twig))->updateStatus((int) $matches[1]);
    exit;
}

// Gestion des étudiants par le pilote : fiche détail, suppression, création, édition
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

// Gestion des offres par le pilote : création, édition, suppression
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

// Administration des pilotes : création, édition, suppression (admin uniquement)
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

// Administration des entreprises : création, édition, suppression (admin uniquement)
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

// Administration des promotions : création, édition, suppression (admin uniquement)
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
|--------------------------------------------------------------------------
| Routes statiques
|--------------------------------------------------------------------------
| Ces routes ont une URL fixe sans paramètre variable.
| Le switch est évalué après les routes dynamiques pour ne pas masquer ces dernières.
| Chaque case instancie le contrôleur concerné, appelle sa méthode et termine avec exit.
| Le case 'default' retourne une erreur 404 pour toute URL non reconnue.
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