<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Modèle Offre de stage.
 * 
 * Modèle ajouté en prévision de la refactorisation MVC complète.
 * Il correspond à l'entité OFFRE du Modèle Conceptuel de Données.
 */
class Offer
{
    private ?int $id;
    private string $titre;
    private ?string $entreprise; // Nom d'entreprise (texte libre)
    private string $lieu;
    private int $dureeSemaines;
    private float $remuneration;
    private string $description;
    private ?string $createdAt;

    // Propriétés liées à la table Entreprise (Jointure)
    private ?int $entrepriseId;
    private ?string $entrepriseNom;
    private ?string $entrepriseSiret;
    private ?string $entrepriseSecteur;
    private ?string $entrepriseVille;
    private ?string $entrepriseSiteWeb;
    private ?float $entrepriseNote;

    public function __construct(
        ?int $id = null,
        string $titre = '',
        ?string $entreprise = null,
        string $lieu = '',
        int $dureeSemaines = 0,
        float $remuneration = 0.0,
        string $description = '',
        ?string $createdAt = null
    ) {
        $this->id = $id;
        $this->titre = $titre;
        $this->entreprise = $entreprise;
        $this->lieu = $lieu;
        $this->dureeSemaines = $dureeSemaines;
        $this->remuneration = $remuneration;
        $this->description = $description;
        $this->createdAt = $createdAt;
    }

    // Getters pour les champs de l'offre
    public function getId(): ?int { return $this->id; }
    public function getTitre(): string { return $this->titre; }
    public function getEntreprise(): ?string { return $this->entreprise; }
    public function getLieu(): string { return $this->lieu; }
    public function getDureeSemaines(): int { return $this->dureeSemaines; }
    public function getRemuneration(): float { return $this->remuneration; }
    public function getDescription(): string { return $this->description; }
    public function getCreatedAt(): ?string { return $this->createdAt; }

    // Getters pour la jointure entreprise (utilisés par Twig via {{ offer.entreprise_nom }})
    public function getEntrepriseId(): ?int { return $this->entrepriseId; }
    public function getEntrepriseNom(): ?string { return $this->entrepriseNom; }
    public function getEntrepriseSiret(): ?string { return $this->entrepriseSiret; }
    public function getEntrepriseSecteur(): ?string { return $this->entrepriseSecteur; }
    public function getEntrepriseVille(): ?string { return $this->entrepriseVille; }
    public function getEntrepriseSiteWeb(): ?string { return $this->entrepriseSiteWeb; }
    public function getEntrepriseNote(): ?float { return $this->entrepriseNote; }

    // Setters
    public function setId(?int $id): void { $this->id = $id; }
    public function setTitre(string $titre): void { $this->titre = $titre; }
    public function setEntreprise(?string $entreprise): void { $this->entreprise = $entreprise; }
    public function setLieu(string $lieu): void { $this->lieu = $lieu; }
    public function setDureeSemaines(int $dureeSemaines): void { $this->dureeSemaines = $dureeSemaines; }
    public function setRemuneration(float $remuneration): void { $this->remuneration = $remuneration; }
    public function setDescription(string $description): void { $this->description = $description; }
    public function setCreatedAt(?string $createdAt): void { $this->createdAt = $createdAt; }

    // Setters pour la jointure
    public function setEntrepriseId(?int $entrepriseId): void { $this->entrepriseId = $entrepriseId; }
    public function setEntrepriseNom(?string $entrepriseNom): void { $this->entrepriseNom = $entrepriseNom; }
    public function setEntrepriseSiret(?string $entrepriseSiret): void { $this->entrepriseSiret = $entrepriseSiret; }
    public function setEntrepriseSecteur(?string $entrepriseSecteur): void { $this->entrepriseSecteur = $entrepriseSecteur; }
    public function setEntrepriseVille(?string $entrepriseVille): void { $this->entrepriseVille = $entrepriseVille; }
    public function setEntrepriseSiteWeb(?string $entrepriseSiteWeb): void { $this->entrepriseSiteWeb = $entrepriseSiteWeb; }
    public function setEntrepriseNote(?float $entrepriseNote): void { $this->entrepriseNote = $entrepriseNote; }
}
