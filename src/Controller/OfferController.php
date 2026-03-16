<?php

namespace App\Controller;

use App\Database;
use Twig\Environment;

class OfferController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(): string
    {
        $pdo = Database::getConnection();

        $selectedSkill = isset($_GET['skills']) ? trim($_GET['skills']) : '';
        $selectedLocation = isset($_GET['location']) ? trim($_GET['location']) : '';
        $selectedDuration = isset($_GET['duration']) ? trim($_GET['duration']) : '';
        $selectedSalary = isset($_GET['salary']) ? trim($_GET['salary']) : '';

        $sql = "
            SELECT DISTINCT o.*
            FROM offres o
            LEFT JOIN offre_competence oc ON oc.offre_id = o.id
            LEFT JOIN competences c ON c.id = oc.competence_id
            WHERE 1=1
        ";

        $params = [];

        if ($selectedSkill !== '') {
            $sql .= " AND c.nom LIKE :skill ";
            $params['skill'] = '%' . $selectedSkill . '%';
        }

        if ($selectedLocation !== '') {
            $sql .= " AND o.lieu LIKE :location ";
            $params['location'] = '%' . $selectedLocation . '%';
        }

        if ($selectedDuration !== '' && is_numeric($selectedDuration)) {
            $sql .= " AND o.duree_semaines <= :duration ";
            $params['duration'] = (int) $selectedDuration;
        }

        if ($selectedSalary !== '' && is_numeric($selectedSalary)) {
            $sql .= " AND o.remuneration >= :salary ";
            $params['salary'] = (float) $selectedSalary;
        }

        $sql .= " ORDER BY o.created_at DESC ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $offers = $stmt->fetchAll();

        foreach ($offers as &$offer) {
            $stmtSkills = $pdo->prepare("
                SELECT c.nom
                FROM competences c
                INNER JOIN offre_competence oc ON oc.competence_id = c.id
                WHERE oc.offre_id = :id
                ORDER BY c.nom ASC
            ");

            $stmtSkills->execute([
                'id' => $offer['id']
            ]);

            $offer['skills'] = $stmtSkills->fetchAll();
        }

        return $this->twig->render('offers.html.twig', [
            'site_name' => 'Help Me Stage',
            'offers' => $offers,
            'selected_skill' => $selectedSkill,
            'selected_location' => $selectedLocation,
            'selected_duration' => $selectedDuration,
            'selected_salary' => $selectedSalary
        ]);
    }
}