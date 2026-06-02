<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Repository des statistiques globales (tables : offres, candidatures, student_wishlist)
 *
 * Fournit les données agrégées pour la page de statistiques accessible aux pilotes/admins.
 */
class StatisticsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Compte le nombre total d'offres de stage dans la plateforme */
    public function countOffers(): int
    {
        return (int) $this->pdo
            ->query('SELECT COUNT(*) FROM offres')
            ->fetchColumn();
    }

    /**
     * Calcule la moyenne de candidatures reçues par offre.
     * La sous-requête calcule d'abord le COUNT par offre (y compris les offres à 0 candidature
     * grâce au LEFT JOIN), puis AVG() est appliqué sur ces totaux.
     * COALESCE(..., 0) gère le cas où il n'y a aucune offre (évite NULL).
     * Le résultat est arrondi à 1 décimale.
     */
    public function getAverageApplicationsPerOffer(): float
    {
        $stmt = $this->pdo->query('
            SELECT COALESCE(AVG(application_count), 0)
            FROM (
                SELECT o.id, COUNT(c.id) AS application_count
                FROM offres o
                LEFT JOIN candidatures c ON c.offre_id = o.id
                GROUP BY o.id
            ) AS offer_stats
        ');

        return round((float) $stmt->fetchColumn(), 1);
    }

    /** Retourne la répartition des offres par durée en semaines — pour le graphique de distribution */
    public function getOffersByDuration(): array
    {
        $stmt = $this->pdo->query('
            SELECT
                duree_semaines,
                COUNT(*) AS total
            FROM offres
            WHERE duree_semaines IS NOT NULL
            GROUP BY duree_semaines
            ORDER BY duree_semaines ASC
        ');

        return $stmt->fetchAll();
    }

    /**
     * Retourne les N offres les plus ajoutées en wishlist.
     * COUNT(sw.offre_id) compte le nombre de fois qu'une offre a été mise en wishlist.
     * Trié par wishlist_count DESC puis par titre ASC pour départager les ex aequo.
     */
    public function getTopWishlistOffers(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                o.id,
                o.titre,
                COALESCE(e.nom, o.entreprise) AS entreprise,
                COUNT(sw.offre_id) AS wishlist_count
            FROM student_wishlist sw
            INNER JOIN offres o ON o.id = sw.offre_id
            LEFT JOIN entreprises e ON e.id = o.entreprise_id
            GROUP BY o.id, o.titre, entreprise
            ORDER BY wishlist_count DESC, o.titre ASC
            LIMIT :limit
        ');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}