<?php

/**
 * Script de création des utilisateurs de test (comptes de démonstration).
 *
 * ATTENTION : ce script supprime TOUS les utilisateurs existants avant d'insérer les comptes.
 * À n'utiliser qu'en environnement de développement pour réinitialiser les comptes.
 *
 * Comptes créés :
 *  - etudiant@helpmestage.fr  / stage2026  (rôle : etudiant)
 *  - pilote@helpmestage.fr    / pilote2026 (rôle : pilote)
 *
 * Utilisation (depuis la racine du projet) :
 *   php create_test_users.php
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Database;

$pdo = Database::getConnection();

// Supprime tous les utilisateurs pour repartir sur une base propre
$pdo->exec("DELETE FROM users");

$stmt = $pdo->prepare("
    INSERT INTO users (nom, prenom, email, password_hash, role)
    VALUES (:nom, :prenom, :email, :password_hash, :role)
");

$users = [
    [
        'nom' => 'Dupont',
        'prenom' => 'Alice',
        'email' => 'etudiant@helpmestage.fr',
        'password_hash' => password_hash('stage2026', PASSWORD_DEFAULT),
        'role' => 'etudiant',
    ],
    [
        'nom' => 'Martin',
        'prenom' => 'Paul',
        'email' => 'pilote@helpmestage.fr',
        'password_hash' => password_hash('pilote2026', PASSWORD_DEFAULT),
        'role' => 'pilote',
    ],
];

foreach ($users as $user) {
    $stmt->execute($user);
}

echo "Utilisateurs créés.\n";