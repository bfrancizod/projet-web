<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Database;

$pdo = Database::getConnection();

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