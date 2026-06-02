<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Repository des utilisateurs.
 */
class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

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

    public function findUserIdByEmail(string $email): ?int
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM users
            WHERE email = :email
            LIMIT 1
        ");

        $stmt->execute([
            'email' => $email,
        ]);

        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    public function createPasswordResetRequest(string $email): void
    {
        $userId = $this->findUserIdByEmail($email);

        $stmt = $this->pdo->prepare("
            INSERT INTO password_reset_requests (user_id, email)
            VALUES (:user_id, :email)
        ");

        $stmt->execute([
            'user_id' => $userId,
            'email' => $email,
        ]);
    }

    public function findPasswordResetRequests(): array
    {
        $stmt = $this->pdo->query("
            SELECT
                id,
                user_id,
                email,
                status,
                created_at,
                processed_at
            FROM password_reset_requests
            ORDER BY created_at DESC
        ");

        return $stmt->fetchAll();
    }

    public function findPasswordResetRequestById(int $id): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT
                id,
                user_id,
                email,
                status
            FROM password_reset_requests
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            'id' => $id,
        ]);

        return $stmt->fetch();
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE users
            SET password_hash = :password_hash
            WHERE id = :id
        ");

        $stmt->execute([
            'password_hash' => $passwordHash,
            'id' => $userId,
        ]);
    }

    public function markPasswordResetRequestAsProcessed(int $id): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE password_reset_requests
            SET status = 'traitee',
                processed_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'id' => $id,
        ]);
    }
}