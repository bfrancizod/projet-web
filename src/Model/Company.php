<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Modèle Entreprise.
 * 
 * Modèle ajouté en prévision de la refactorisation MVC complète.
 * Il correspond à l'entité ENTREPRISE du Modèle Conceptuel de Données.
 */
class Company
{
    private ?int $id;
    private string $nom;
    private ?string $siret;
    private ?string $secteur;
    private ?string $ville;
    private ?string $siteWeb;
    private ?float $note;
    private ?string $commentaire;

    public function __construct(
        ?int $id = null,
        string $nom = '',
        ?string $siret = null,
        ?string $secteur = null,
        ?string $ville = null,
        ?string $siteWeb = null,
        ?float $note = null,
        ?string $commentaire = null
    ) {
        $this->id = $id;
        $this->nom = $nom;
        $this->siret = $siret;
        $this->secteur = $secteur;
        $this->ville = $ville;
        $this->siteWeb = $siteWeb;
        $this->note = $note;
        $this->commentaire = $commentaire;
    }

    // Getters
    public function getId(): ?int { return $this->id; }
    public function getNom(): string { return $this->nom; }
    public function getSiret(): ?string { return $this->siret; }
    public function getSecteur(): ?string { return $this->secteur; }
    public function getVille(): ?string { return $this->ville; }
    public function getSiteWeb(): ?string { return $this->siteWeb; }
    public function getNote(): ?float { return $this->note; }
    public function getCommentaire(): ?string { return $this->commentaire; }

    // Setters
    public function setId(?int $id): void { $this->id = $id; }
    public function setNom(string $nom): void { $this->nom = $nom; }
    public function setSiret(?string $siret): void { $this->siret = $siret; }
    public function setSecteur(?string $secteur): void { $this->secteur = $secteur; }
    public function setVille(?string $ville): void { $this->ville = $ville; }
    public function setSiteWeb(?string $siteWeb): void { $this->siteWeb = $siteWeb; }
    public function setNote(?float $note): void { $this->note = $note; }
    public function setCommentaire(?string $commentaire): void { $this->commentaire = $commentaire; }
}
