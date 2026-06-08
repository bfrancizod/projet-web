<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Modèle Étudiant qui hérite de Utilisateur.
 * 
 * Modèle ajouté en prévision de la refactorisation MVC complète.
 * Il correspond à l'entité ÉTUDIANT du Modèle Conceptuel de Données et illustre la notion d'héritage ("EST").
 */
class Student extends User
{
    private string $formation;
    private string $status;
    private ?string $lastActivity;

    public function __construct(
        ?int $id = null,
        string $nom = '',
        string $prenom = '',
        string $email = '',
        string $role = 'etudiant',
        ?string $createdAt = null,
        string $formation = '',
        string $status = '',
        ?string $lastActivity = null
    ) {
        parent::__construct($id, $nom, $prenom, $email, $role, $createdAt);
        $this->formation = $formation;
        $this->status = $status;
        $this->lastActivity = $lastActivity;
    }

    public function getFormation(): string { return $this->formation; }
    public function setFormation(string $formation): void { $this->formation = $formation; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): void { $this->status = $status; }

    public function getLastActivity(): ?string { return $this->lastActivity; }
    public function setLastActivity(?string $lastActivity): void { $this->lastActivity = $lastActivity; }
}
