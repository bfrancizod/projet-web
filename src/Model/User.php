<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Modèle de base Utilisateur.
 * 
 * Modèle ajouté en prévision de la refactorisation MVC complète.
 * Il correspond à l'entité UTILISATEUR du Modèle Conceptuel de Données.
 */
class User
{
    protected ?int $id;
    protected string $nom;
    protected string $prenom;
    protected string $email;
    protected string $role;
    protected ?string $createdAt;

    public function __construct(
        ?int $id = null,
        string $nom = '',
        string $prenom = '',
        string $email = '',
        string $role = '',
        ?string $createdAt = null
    ) {
        $this->id = $id;
        $this->nom = $nom;
        $this->prenom = $prenom;
        $this->email = $email;
        $this->role = $role;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function setId(?int $id): void { $this->id = $id; }

    public function getNom(): string { return $this->nom; }
    public function setNom(string $nom): void { $this->nom = $nom; }

    public function getPrenom(): string { return $this->prenom; }
    public function setPrenom(string $prenom): void { $this->prenom = $prenom; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }

    public function getRole(): string { return $this->role; }
    public function setRole(string $role): void { $this->role = $role; }

    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function setCreatedAt(?string $createdAt): void { $this->createdAt = $createdAt; }
}
