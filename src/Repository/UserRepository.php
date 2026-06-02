<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Repository des utilisateurs (table : users)
 *
 * Contient uniquement les opérations génériques sur les utilisateurs.
 * Les opérations spécifiques aux étudiants sont dans StudentRepository,
 * celles des pilotes dans PilotRepository.
 */
class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Récupère un utilisateur par email pour la page de connexion.
     * Retourne le password_hash pour vérification via password_verify().
     */
    public function findLoginUserByEmail(string $email): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT
                id,
                nom,
                prenom,
                email,
                password_hash,
                role
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute([
            'email' => $email,
        ]);

        return $stmt->fetch();
    }

    /** Retrouve un utilisateur par son ID — sans le mot de passe hashé */
    public function findById(int $userId): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT id, nom, prenom, email, role
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([
            'id' => $userId,
        ]);

        return $stmt->fetch();
    }
}