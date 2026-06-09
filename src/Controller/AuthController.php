<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\UserRepository;
use App\Security\Csrf;
use App\Security\RateLimiter;
use Twig\Environment;

class AuthController
{
    private Environment $twig;
    private UserRepository $userRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->userRepository = new UserRepository(Database::getConnection());
    }

    public function login(): string
    {
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Rate limiting : max 5 tentatives par 15 minutes par IP
            $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            $rateLimitKey = 'login_' . $clientIp;

            if (!RateLimiter::checkLimit($rateLimitKey, 5, 900)) {
                $error = 'Trop de tentatives. Réessayez dans 15 minutes.';
            } else {
                Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

                $email = trim((string) ($_POST['email'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Adresse email invalide.';
                } elseif ($password === '') {
                    $error = 'Mot de passe requis.';
                } else {
                $user = $this->userRepository->findLoginUserByEmail($email);

                if ($user && password_verify($password, $user['password_hash'])) {
                    RateLimiter::reset($rateLimitKey);
                    session_regenerate_id(true);

                    $_SESSION['user'] = [
                        'id' => (int) $user['id'],
                        'nom' => $user['nom'],
                        'prenom' => $user['prenom'],
                        'email' => $user['email'],
                        'role' => $user['role'],
                    ];

                    Csrf::rotate();

                    if ($user['role'] === 'etudiant') {
                        header('Location: /espace-etudiant');
                        exit;
                    }

                    if ($user['role'] === 'pilote') {
                        header('Location: /espace-pilote');
                        exit;
                    }

                    if ($user['role'] === 'administrateur') {
                        header('Location: /espace-admin');
                        exit;
                    }

                    unset($_SESSION['user']);
                    $error = 'Rôle utilisateur non autorisé.';
                } else {
                    $error = 'Email ou mot de passe incorrect.';
                }
                }
            }
        }

        return $this->twig->render('login.html.twig', [
            'site_name' => 'Help Me Stage',
            'error' => $error,
        ]);
    }

    public function forgotPassword(): string
    {
        $error = null;
        $success = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

            $email = trim((string) ($_POST['email'] ?? ''));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Adresse email invalide.';
            } else {
                $this->userRepository->createPasswordResetRequest($email);
                $success = true;
            }
        }

        return $this->twig->render('forgot-password.html.twig', [
            'error' => $error,
            'success' => $success,
        ]);
    }

    public function adminPasswordRequests(): string
    {
        if (($_SESSION['user']['role'] ?? null) !== 'administrateur') {
            header('Location: /connexion');
            exit;
        }

        // Whitelist des messages d'erreur acceptés en GET (évite XSS via ?error=<payload>)
        $allowedErrors = [
            'Utilisateur introuvable',
            'Le mot de passe doit contenir au moins 8 caractères',
            'Demande introuvable ou expirée (validité : 24h)',
        ];

        $errorMessage = null;
        $rawError = $_GET['error'] ?? null;
        if ($rawError && in_array($rawError, $allowedErrors, true)) {
            $errorMessage = $rawError;
        }

        return $this->twig->render('admin-password-requests.html.twig', [
            'requests' => $this->userRepository->findPasswordResetRequests(),
            'success' => isset($_GET['success']),
            'deleted' => isset($_GET['deleted']),
            'error' => $errorMessage,
        ]);
    }

    public function adminChangePassword(int $requestId): void
    {
        if (($_SESSION['user']['role'] ?? null) !== 'administrateur') {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $request = $this->userRepository->findPasswordResetRequestById($requestId);

        if (!$request) {
            header('Location: /admin-demandes-mdp?error=Demande introuvable ou expirée (validité : 24h)');
            exit;
        }

        if (empty($request['user_id'])) {
            header('Location: /admin-demandes-mdp?error=Utilisateur introuvable');
            exit;
        }

        $password = (string) ($_POST['password'] ?? '');

        if (strlen($password) < 8) {
            header('Location: /admin-demandes-mdp?error=Le mot de passe doit contenir au moins 8 caractères');
            exit;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $this->userRepository->updatePassword((int) $request['user_id'], $passwordHash);
        $this->userRepository->markPasswordResetRequestAsProcessed($requestId);

        header('Location: /admin-demandes-mdp?success=1');
        exit;
    }

    /** Supprime une demande de réinitialisation de mot de passe (action admin) */
    public function adminDeletePasswordRequest(int $requestId): void
    {
        if (($_SESSION['user']['role'] ?? null) !== 'administrateur') {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $this->userRepository->deletePasswordResetRequest($requestId);

        header('Location: /admin-demandes-mdp?deleted=1');
        exit;
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }

        session_destroy();

        header('Location: /connexion');
        exit;
    }
}