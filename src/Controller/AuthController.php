<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;
use Twig\Environment;

class AuthController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function login(): string
    {
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Adresse email invalide.';
            } elseif ($password === '') {
                $error = 'Mot de passe requis.';
            } else {
                $pdo = Database::getConnection();

                $stmt = $pdo->prepare("
                    SELECT id, nom, prenom, email, password_hash, role
                    FROM users
                    WHERE email = :email
                    LIMIT 1
                ");
                $stmt->execute(['email' => $email]);

                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
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

        return $this->twig->render('login.html.twig', [
            'site_name' => 'Help Me Stage',
            'error' => $error,
        ]);
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