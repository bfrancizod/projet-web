<?php

namespace App\Controller;

use App\Database;
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
            $email = trim($_POST['email'] ?? '');
            $password = trim($_POST['password'] ?? '');

            $pdo = Database::getConnection();

            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([
                'email' => $email
            ]);

            $user = $stmt->fetch();

            if ($user !== false && password_verify($password, $user['password_hash'])) {
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'nom' => $user['nom'],
                    'prenom' => $user['prenom'],
                    'email' => $user['email'],
                    'role' => $user['role']
                ];

                if ($user['role'] === 'etudiant') {
                    header('Location: /espace-etudiant');
                    exit;
                }

                if ($user['role'] === 'pilote') {
                    header('Location: /espace-pilote');
                    exit;
                }
            }

            $error = 'Email ou mot de passe incorrect.';
        }

        return $this->twig->render('login.html.twig', [
            'site_name' => 'Help Me Stage',
            'error' => $error
        ]);
    }

    public function logout(): void
    {
        unset($_SESSION['user']);
        session_destroy();

        header('Location: /connexion');
        exit;
    }
}