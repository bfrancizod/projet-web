<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\UserRepository;
use App\Security\Csrf;
use Twig\Environment;

/**
 * Contrôleur d'authentification
 *
 * Gère la connexion et la déconnexion des utilisateurs.
 * Après une connexion réussie, redirige selon le rôle :
 * etudiant → /espace-etudiant, pilote → /espace-pilote, administrateur → /espace-admin.
 */
class AuthController
{
    private Environment $twig;
    private UserRepository $userRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->userRepository = new UserRepository(Database::getConnection());
    }

    /**
     * Affiche et traite le formulaire de connexion.
     *
     * Sécurité :
     * - Vérification du token CSRF avant tout traitement
     * - password_verify() pour comparer sans timing attack
     * - session_regenerate_id() pour prévenir la fixation de session
     * - Csrf::rotate() pour invalider le token pré-connexion
     * - Seuls les champs nécessaires sont stockés en session (pas le mot de passe)
     */
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
                $user = $this->userRepository->findLoginUserByEmail($email);

                // password_verify() est résistant aux timing attacks contrairement à ===
                // $user peut être false si l'email n'existe pas — l'opérateur && court-circuite
                // et n'appelle pas password_verify() dans ce cas (évite une erreur)
                if ($user && password_verify($password, $user['password_hash'])) {
                    // Génère un nouvel ID de session pour prévenir la fixation de session :
                    // un attaquant qui connaissait l'ancien ID ne peut plus l'utiliser après login
                    session_regenerate_id(true);

                    $_SESSION['user'] = [
                        'id' => (int) $user['id'],
                        'nom' => $user['nom'],
                        'prenom' => $user['prenom'],
                        'email' => $user['email'],
                        'role' => $user['role'],
                    ];

                    // Invalide le token CSRF pré-connexion pour éviter sa réutilisation
                    Csrf::rotate();

                    // Redirection selon le rôle de l'utilisateur connecté
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

                    // Rôle inconnu : on supprime la session créée et on affiche une erreur
                    unset($_SESSION['user']);
                    $error = 'Rôle utilisateur non autorisé.';
                } else {
                    // Message volontairement vague : ne pas révéler si c'est l'email ou le mot de passe
                    // qui est incorrect (évite l'énumération de comptes valides)
                    $error = 'Email ou mot de passe incorrect.';
                }
            }
        }

        return $this->twig->render('login.html.twig', [
            'site_name' => 'Help Me Stage',
            'error' => $error,
        ]);
    }

    /**
     * Déconnecte l'utilisateur proprement.
     *
     * Les trois étapes sont nécessaires pour une déconnexion complète :
     * 1. Vider le tableau $_SESSION (données en mémoire)
     * 2. Supprimer le cookie de session côté navigateur (sinon le cookie reste même si la session est détruite)
     * 3. Détruire la session côté serveur
     */
    public function logout(): void
    {
        // Vide toutes les données de session en mémoire
        $_SESSION = [];

        // Supprime le cookie de session dans le navigateur :
        // time() - 42000 place la date d'expiration dans le passé → le navigateur supprime le cookie.
        // On réutilise les mêmes paramètres (secure, httponly, path) que lors de la création.
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