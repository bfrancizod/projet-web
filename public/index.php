<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use App\Controller\HomeController;
use App\Controller\OfferController;
use App\Controller\OfferDetailController;
use App\Controller\AuthController;
use App\Controller\StudentDashboardController;
use App\Controller\PilotDashboardController;
use App\Controller\StudentApplicationsController;
use App\Controller\StudentWishlistController;
use App\Controller\PilotStudentsController;
use App\Controller\PilotOffersController;
use App\Controller\PilotApplicationsController;
use App\Controller\ApplyController;
use App\Controller\LegalController;
use App\Controller\ContactController;
use App\Controller\PrivacyController;

$loader = new FilesystemLoader(__DIR__ . '/../templates');

$twig = new Environment($loader, [
    'cache' => false,
]);

$twig->addGlobal('current_user', $_SESSION['user'] ?? null);

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/offres/([0-9]+)/postuler$#', $uri, $matches)) {
    $controller = new ApplyController($twig);
    echo $controller->form((int) $matches[1]);
    exit;
}

switch ($uri) {
    case '/':
    case '':
        $controller = new HomeController($twig);
        echo $controller->index();
        break;

    case '/offres':
        $controller = new OfferController($twig);
        echo $controller->index();
        break;

    case '/offre':
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            echo 'ID offre invalide';
            break;
        }

        $controller = new OfferDetailController($twig);
        echo $controller->show((int) $_GET['id']);
        break;

    case '/connexion':
        $controller = new AuthController($twig);
        echo $controller->login();
        break;

    case '/logout':
        $controller = new AuthController($twig);
        $controller->logout();
        break;

    case '/espace-etudiant':
        $controller = new StudentDashboardController($twig);
        echo $controller->index();
        break;

    case '/espace-pilote':
        $controller = new PilotDashboardController($twig);
        echo $controller->index();
        break;

    case '/etudiant-candidatures':
        $controller = new StudentApplicationsController($twig);
        echo $controller->index();
        break;

    case '/etudiant-wishlist':
        $controller = new StudentWishlistController($twig);
        echo $controller->index();
        break;

    case '/pilot-etudiants':
        $controller = new PilotStudentsController($twig);
        echo $controller->index();
        break;

    case '/pilot-offres':
        $controller = new PilotOffersController($twig);
        echo $controller->index();
        break;

    case '/pilot-candidatures':
        $controller = new PilotApplicationsController($twig);
        echo $controller->index();
        break;

    case '/mentions-legales':
        $controller = new LegalController($twig);
        echo $controller->index();
        break;
    
    case '/contact':
    $controller = new ContactController($twig);
    echo $controller->index();
    break;

    case '/politique-confidentialite':
        $controller = new PrivacyController($twig);
        echo $controller->index();
        break;

    default:
        http_response_code(404);
        echo 'Page non trouvée';
        break;
    
}