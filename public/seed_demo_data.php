<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function randomDate(string $start = '2026-01-01', string $end = '2026-03-17'): string
{
    $min = strtotime($start);
    $max = strtotime($end);

    return date('Y-m-d', random_int($min, $max));
}

function getOrCreateCompetence(PDO $pdo, string $name): int
{
    $stmt = $pdo->prepare("SELECT id FROM competences WHERE nom = :nom LIMIT 1");
    $stmt->execute(['nom' => $name]);
    $id = $stmt->fetchColumn();

    if ($id) {
        return (int) $id;
    }

    $stmt = $pdo->prepare("INSERT INTO competences (nom) VALUES (:nom)");
    $stmt->execute(['nom' => $name]);

    return (int) $pdo->lastInsertId();
}

function getOrCreateStudent(PDO $pdo, array $student): int
{
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $student['email']]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $userId = (int) $existingId;

        $stmt = $pdo->prepare("
            UPDATE users
            SET nom = :nom,
                prenom = :prenom,
                password_hash = :password_hash,
                role = 'etudiant'
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $userId,
            'nom' => $student['nom'],
            'prenom' => $student['prenom'],
            'password_hash' => password_hash($student['password'], PASSWORD_DEFAULT),
        ]);

        $stmt = $pdo->prepare("SELECT user_id FROM student_profiles WHERE user_id = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $userId]);

        if ($stmt->fetchColumn()) {
            $stmt = $pdo->prepare("
                UPDATE student_profiles
                SET formation = :formation,
                    telephone = :telephone,
                    promotion = :promotion,
                    status = :status,
                    last_activity = :last_activity
                WHERE user_id = :user_id
            ");
            $stmt->execute([
                'user_id' => $userId,
                'formation' => $student['formation'],
                'telephone' => $student['telephone'],
                'promotion' => $student['promotion'],
                'status' => $student['status'],
                'last_activity' => $student['last_activity'],
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO student_profiles (user_id, formation, telephone, promotion, status, last_activity)
                VALUES (:user_id, :formation, :telephone, :promotion, :status, :last_activity)
            ");
            $stmt->execute([
                'user_id' => $userId,
                'formation' => $student['formation'],
                'telephone' => $student['telephone'],
                'promotion' => $student['promotion'],
                'status' => $student['status'],
                'last_activity' => $student['last_activity'],
            ]);
        }

        return $userId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO users (nom, prenom, email, password_hash, role)
        VALUES (:nom, :prenom, :email, :password_hash, 'etudiant')
    ");
    $stmt->execute([
        'nom' => $student['nom'],
        'prenom' => $student['prenom'],
        'email' => $student['email'],
        'password_hash' => password_hash($student['password'], PASSWORD_DEFAULT),
    ]);

    $userId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT INTO student_profiles (user_id, formation, telephone, promotion, status, last_activity)
        VALUES (:user_id, :formation, :telephone, :promotion, :status, :last_activity)
    ");
    $stmt->execute([
        'user_id' => $userId,
        'formation' => $student['formation'],
        'telephone' => $student['telephone'],
        'promotion' => $student['promotion'],
        'status' => $student['status'],
        'last_activity' => $student['last_activity'],
    ]);

    return $userId;
}

function getOrCreateOffer(PDO $pdo, array $offer): int
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM offres
        WHERE titre = :titre
          AND entreprise = :entreprise
          AND lieu = :lieu
        LIMIT 1
    ");
    $stmt->execute([
        'titre' => $offer['titre'],
        'entreprise' => $offer['entreprise'],
        'lieu' => $offer['lieu'],
    ]);

    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $offerId = (int) $existingId;

        $stmt = $pdo->prepare("
            UPDATE offres
            SET remuneration = :remuneration,
                duree_semaines = :duree_semaines,
                description = :description,
                created_at = :created_at
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $offerId,
            'remuneration' => $offer['remuneration'],
            'duree_semaines' => $offer['duree_semaines'],
            'description' => $offer['description'],
            'created_at' => $offer['created_at'],
        ]);

        return $offerId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO offres (titre, entreprise, lieu, remuneration, duree_semaines, description, created_at)
        VALUES (:titre, :entreprise, :lieu, :remuneration, :duree_semaines, :description, :created_at)
    ");
    $stmt->execute([
        'titre' => $offer['titre'],
        'entreprise' => $offer['entreprise'],
        'lieu' => $offer['lieu'],
        'remuneration' => $offer['remuneration'],
        'duree_semaines' => $offer['duree_semaines'],
        'description' => $offer['description'],
        'created_at' => $offer['created_at'],
    ]);

    return (int) $pdo->lastInsertId();
}

function attachStudentCompetence(PDO $pdo, int $userId, int $competenceId): void
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM student_competence
        WHERE user_id = :user_id AND competence_id = :competence_id
        LIMIT 1
    ");
    $stmt->execute([
        'user_id' => $userId,
        'competence_id' => $competenceId,
    ]);

    if (!$stmt->fetchColumn()) {
        $stmt = $pdo->prepare("
            INSERT INTO student_competence (user_id, competence_id)
            VALUES (:user_id, :competence_id)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'competence_id' => $competenceId,
        ]);
    }
}

function attachOfferCompetence(PDO $pdo, int $offerId, int $competenceId): void
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM offre_competence
        WHERE offre_id = :offre_id AND competence_id = :competence_id
        LIMIT 1
    ");
    $stmt->execute([
        'offre_id' => $offerId,
        'competence_id' => $competenceId,
    ]);

    if (!$stmt->fetchColumn()) {
        $stmt = $pdo->prepare("
            INSERT INTO offre_competence (offre_id, competence_id)
            VALUES (:offre_id, :competence_id)
        ");
        $stmt->execute([
            'offre_id' => $offerId,
            'competence_id' => $competenceId,
        ]);
    }
}

function addWishlist(PDO $pdo, int $userId, int $offerId): void
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM student_wishlist
        WHERE user_id = :user_id AND offre_id = :offre_id
        LIMIT 1
    ");
    $stmt->execute([
        'user_id' => $userId,
        'offre_id' => $offerId,
    ]);

    if (!$stmt->fetchColumn()) {
        $stmt = $pdo->prepare("
            INSERT INTO student_wishlist (user_id, offre_id, created_at)
            VALUES (:user_id, :offre_id, :created_at)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'offre_id' => $offerId,
            'created_at' => randomDate(),
        ]);
    }
}

function addApplication(PDO $pdo, int $userId, int $offerId, string $status, string $studentEmail): void
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM candidatures
        WHERE student_user_id = :user_id AND offre_id = :offre_id
        LIMIT 1
    ");
    $stmt->execute([
        'user_id' => $userId,
        'offre_id' => $offerId,
    ]);

    if (!$stmt->fetchColumn()) {
        $stmt = $pdo->prepare("
            INSERT INTO candidatures (
                student_user_id,
                offre_id,
                status,
                lettre_motivation,
                cv_filename,
                created_at
            )
            VALUES (
                :user_id,
                :offre_id,
                :status,
                :lettre_motivation,
                :cv_filename,
                :created_at
            )
        ");
        $stmt->execute([
            'user_id' => $userId,
            'offre_id' => $offerId,
            'status' => $status,
            'lettre_motivation' => "Bonjour, je suis étudiant en CPI A2 et je souhaite candidater à cette offre. Mon profil, ma motivation et mes compétences techniques correspondent bien aux attentes du poste.",
            'cv_filename' => 'cv-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($studentEmail)) . '.pdf',
            'created_at' => randomDate(),
        ]);
    }
}

$competences = [
    'PHP',
    'Twig',
    'HTML',
    'CSS',
    'JavaScript',
    'MySQL',
    'MariaDB',
    'C',
    'C++',
    'Python',
    'Linux',
    'Git',
    'Réseau',
    'Cisco',
    'Arduino',
    'UML',
    'MVC',
    'SQL',
    'Cybersécurité',
    'API REST',
];

$competenceIds = [];
foreach ($competences as $competence) {
    $competenceIds[$competence] = getOrCreateCompetence($pdo, $competence);
}

$firstNames = [
    'Lucas', 'Hugo', 'Nathan', 'Enzo', 'Ethan',
    'Louis', 'Mathis', 'Noah', 'Tom', 'Adam',
    'Léo', 'Jules', 'Sacha', 'Yanis', 'Clément',
    'Baptiste', 'Alexis', 'Paul', 'Rayan', 'Maxime',
    'Théo', 'Antoine', 'Arthur', 'Nolan', 'Gabriel',
];

$lastNames = [
    'Bernard', 'Dubois', 'Moreau', 'Petit', 'Roux',
    'Fournier', 'Girard', 'Andre', 'Mercier', 'Blanc',
    'Guerin', 'Muller', 'Henry', 'Rousseau', 'Nicolas',
    'Robin', 'Chevalier', 'Lambert', 'Bonnet', 'Francois',
    'Martinez', 'Legrand', 'Garnier', 'Faure', 'Perrin',
];

$studentIds = [];
$studentEmails = [];
$statuses = [
    'sans_stage',
    'en_recherche',
    'stage_trouve',
    'stage_valide',
];

$studentCompetencePool = array_keys($competenceIds);

$pdo->beginTransaction();

try {
    for ($i = 0; $i < 25; $i++) {
        $status = $statuses[$i % count($statuses)];
        $email = 'cpia2.' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '@helpmestage.fr';

        $student = [
            'nom' => $lastNames[$i],
            'prenom' => $firstNames[$i],
            'email' => $email,
            'password' => 'stage2026',
            'formation' => 'CPI A2',
            'promotion' => '2025-2026',
            'telephone' => '06' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => $status,
            'last_activity' => randomDate(),
        ];

        $userId = getOrCreateStudent($pdo, $student);
        $studentIds[] = $userId;
        $studentEmails[$userId] = $email;

        shuffle($studentCompetencePool);
        $selectedSkills = array_slice($studentCompetencePool, 0, random_int(3, 5));

        foreach ($selectedSkills as $skillName) {
            attachStudentCompetence($pdo, $userId, $competenceIds[$skillName]);
        }
    }

    $titles = [
        'Développeur web PHP/Twig',
        'Développeur full stack junior',
        'Assistant administrateur systèmes',
        'Technicien support informatique',
        'Développeur Python',
        'Développeur front-end',
        'Développeur back-end',
        'Analyste cybersécurité junior',
        'Assistant réseau et télécom',
        'Développeur applications métier',
    ];

    $companies = [
        'TechNova', 'DataPulse', 'NetOrion', 'HexaSoft', 'BluePixel',
        'Synapse IT', 'Asteria Digital', 'LogiWave', 'CyberLink', 'InfiniCode'
    ];

    $locations = [
        'Metz', 'Nancy', 'Luxembourg', 'Strasbourg', 'Paris',
        'Lyon', 'Reims', 'Lille', 'Toulouse', 'Orléans'
    ];

    $offerIds = [];
    $offerCompetencePool = array_keys($competenceIds);

    for ($i = 1; $i <= 50; $i++) {
        $title = $titles[($i - 1) % count($titles)] . ' #' . $i;
        $company = $companies[array_rand($companies)];
        $location = $locations[array_rand($locations)];

        $offer = [
            'titre' => $title,
            'entreprise' => $company,
            'lieu' => $location,
            'remuneration' => random_int(550, 900),
            'duree_semaines' => [8, 10, 12, 16][array_rand([8, 10, 12, 16])],
            'description' => "Dans le cadre de son développement, {$company} recherche un stagiaire CPI A2 pour intervenir sur des sujets techniques : développement, base de données, réseau, tests, documentation et amélioration continue.",
            'created_at' => randomDate(),
        ];

        $offerId = getOrCreateOffer($pdo, $offer);
        $offerIds[] = $offerId;

        shuffle($offerCompetencePool);
        $selectedOfferSkills = array_slice($offerCompetencePool, 0, random_int(2, 4));

        foreach ($selectedOfferSkills as $skillName) {
            attachOfferCompetence($pdo, $offerId, $competenceIds[$skillName]);
        }
    }

    $applicationStatuses = ['envoyee', 'en_etude', 'acceptee', 'refusee'];

    foreach ($studentIds as $index => $studentId) {
        $studentStatus = $statuses[$index % count($statuses)];

        shuffle($offerIds);

        $wishlistOffers = array_slice($offerIds, 0, random_int(2, 5));
        foreach ($wishlistOffers as $offerId) {
            addWishlist($pdo, $studentId, $offerId);
        }

        if ($studentStatus === 'sans_stage') {
            $applicationCount = random_int(0, 2);
        } elseif ($studentStatus === 'en_recherche') {
            $applicationCount = random_int(2, 5);
        } elseif ($studentStatus === 'stage_trouve') {
            $applicationCount = random_int(2, 4);
        } else {
            $applicationCount = random_int(1, 3);
        }

        $studentOffers = array_slice($offerIds, 0, $applicationCount);

        foreach ($studentOffers as $k => $offerId) {
            if ($studentStatus === 'stage_valide' && $k === 0) {
                $status = 'acceptee';
            } elseif ($studentStatus === 'stage_trouve' && $k === 0) {
                $status = 'en_etude';
            } else {
                $status = $applicationStatuses[array_rand($applicationStatuses)];
            }

            addApplication($pdo, $studentId, $offerId, $status, $studentEmails[$studentId]);
        }
    }

    $pdo->commit();

    echo "Seed terminé avec succès.\n";
    echo "- 25 étudiants CPI A2 créés/mis à jour\n";
    echo "- 50 offres créées/mises à jour\n";
    echo "- Compétences, wishlists et candidatures générées\n";
    echo "- Mot de passe étudiant par défaut : stage2026\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo "Erreur pendant le seed : " . $e->getMessage() . "\n";
    exit(1);
}