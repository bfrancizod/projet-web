<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Security\Csrf;
use App\Support\PilotPromotionAccess;
use PDO;
use Twig\Environment;

class PilotStudentController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function index(): string
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();
        $currentUserRole = $_SESSION['user']['role'] ?? null;
        $currentUserId = (int) ($_SESSION['user']['id'] ?? 0);

        $allowedPromotionIds = [];
        if ($currentUserRole === 'pilote') {
            $allowedPromotionIds = PilotPromotionAccess::getAssignedPromotionIds($pdo, $currentUserId);
        }

        $selectedPromotionId = isset($_GET['promotion_id']) && ctype_digit((string) $_GET['promotion_id'])
            ? (int) $_GET['promotion_id']
            : null;

        if (
            $currentUserRole === 'pilote'
            && $selectedPromotionId !== null
            && !in_array($selectedPromotionId, $allowedPromotionIds, true)
        ) {
            $selectedPromotionId = null;
        }

        $search = trim((string) ($_GET['q'] ?? ''));
        $currentPage = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0
            ? (int) $_GET['page']
            : 1;

        $perPage = 10;

        if ($currentUserRole === 'pilote') {
            $promotions = PilotPromotionAccess::getPromotionsByIds($pdo, $allowedPromotionIds);
        } else {
            $promotionsStmt = $pdo->query("
                SELECT id, label, academic_year
                FROM promotions
                ORDER BY academic_year DESC, label ASC
            ");
            $promotions = $promotionsStmt->fetchAll();
        }

        $countSql = "
            SELECT COUNT(*)
            FROM users u
            INNER JOIN student_profiles sp ON sp.user_id = u.id
            WHERE u.role = 'etudiant'
        ";

        $countParams = [];

        if ($currentUserRole === 'pilote') {
            if ($allowedPromotionIds === []) {
                $countSql .= " AND 1 = 0 ";
            } else {
                $placeholders = [];
                foreach ($allowedPromotionIds as $index => $promotionId) {
                    $key = ':allowed_promotion_' . $index;
                    $placeholders[] = $key;
                    $countParams['allowed_promotion_' . $index] = $promotionId;
                }
                $countSql .= " AND sp.promotion_id IN (" . implode(', ', $placeholders) . ")";
            }
        }

        if ($selectedPromotionId !== null) {
            $countSql .= " AND sp.promotion_id = :promotion_id";
            $countParams['promotion_id'] = $selectedPromotionId;
        }

        if ($search !== '') {
            $countSql .= "
                AND (
                    u.nom LIKE :search_nom
                    OR u.prenom LIKE :search_prenom
                    OR u.email LIKE :search_email
                )
            ";
            $searchValue = '%' . $search . '%';
            $countParams['search_nom'] = $searchValue;
            $countParams['search_prenom'] = $searchValue;
            $countParams['search_email'] = $searchValue;
        }

        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $totalStudents = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($totalStudents / $perPage));
        if ($currentPage > $totalPages) {
            $currentPage = $totalPages;
        }

        $offset = ($currentPage - 1) * $perPage;

        $sql = "
            SELECT
                u.id,
                u.nom,
                u.prenom,
                u.email,
                sp.formation,
                sp.status,
                sp.last_activity,
                p.label AS promotion_label,
                p.academic_year
            FROM users u
            INNER JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN promotions p ON p.id = sp.promotion_id
            WHERE u.role = 'etudiant'
        ";

        $params = [];

        if ($currentUserRole === 'pilote') {
            if ($allowedPromotionIds === []) {
                $sql .= " AND 1 = 0 ";
            } else {
                $placeholders = [];
                foreach ($allowedPromotionIds as $index => $promotionId) {
                    $key = ':allowed_promotion_' . $index;
                    $placeholders[] = $key;
                    $params['allowed_promotion_' . $index] = $promotionId;
                }
                $sql .= " AND sp.promotion_id IN (" . implode(', ', $placeholders) . ")";
            }
        }

        if ($selectedPromotionId !== null) {
            $sql .= " AND sp.promotion_id = :promotion_id";
            $params['promotion_id'] = $selectedPromotionId;
        }

        if ($search !== '') {
            $sql .= "
                AND (
                    u.nom LIKE :search_nom
                    OR u.prenom LIKE :search_prenom
                    OR u.email LIKE :search_email
                )
            ";
            $searchValue = '%' . $search . '%';
            $params['search_nom'] = $searchValue;
            $params['search_prenom'] = $searchValue;
            $params['search_email'] = $searchValue;
        }

        $sql .= " ORDER BY p.academic_year DESC, p.label ASC, u.nom ASC, u.prenom ASC LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);

        foreach ($params as $name => $value) {
            if (in_array($name, ['search_nom', 'search_prenom', 'search_email'], true)) {
                $stmt->bindValue(':' . $name, $value, PDO::PARAM_STR);
            } else {
                $stmt->bindValue(':' . $name, (int) $value, PDO::PARAM_INT);
            }
        }

        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $students = $stmt->fetchAll();

        return $this->twig->render('pilot-students.html.twig', [
            'students' => $students,
            'promotions' => $promotions,
            'selected_promotion_id' => $selectedPromotionId,
            'search' => $search,
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
        ]);
    }

    public function show(int $studentId): string
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();
        $currentUserRole = $_SESSION['user']['role'] ?? null;

        if ($currentUserRole === 'pilote') {
            $pilotId = (int) ($_SESSION['user']['id'] ?? 0);
            if (!PilotPromotionAccess::pilotCanAccessStudent($pdo, $pilotId, $studentId)) {
                http_response_code(403);
                return 'Accès refusé à cet étudiant.';
            }
        }

        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.nom,
                u.prenom,
                u.email,
                sp.formation,
                sp.status,
                sp.last_activity,
                p.label AS promotion_label,
                p.academic_year
            FROM users u
            INNER JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN promotions p ON p.id = sp.promotion_id
            WHERE u.id = :id
              AND u.role = 'etudiant'
            LIMIT 1
        ");
        $stmt->execute(['id' => $studentId]);
        $student = $stmt->fetch();

        if (!$student) {
            http_response_code(404);
            return 'Étudiant introuvable.';
        }

        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.status,
                c.created_at,
                c.lettre_motivation,
                c.cv_filename,
                o.id AS offre_id,
                o.titre,
                o.lieu,
                o.remuneration,
                o.duree_semaines,
                COALESCE(e.nom, o.entreprise) AS entreprise_nom
            FROM candidatures c
            INNER JOIN offres o ON o.id = c.offre_id
            LEFT JOIN entreprises e ON e.id = o.entreprise_id
            WHERE c.student_user_id = :id
            ORDER BY c.created_at DESC
        ");
        $stmt->execute(['id' => $studentId]);
        $applications = $stmt->fetchAll();

        return $this->twig->render('pilot-student-detail.html.twig', [
            'student' => $student,
            'applications' => $applications,
        ]);
    }

    public function create(): string
    {
        return $this->handleForm(null);
    }

    public function edit(int $studentId): string
    {
        return $this->handleForm($studentId);
    }

    private function handleForm(?int $studentId): string
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        $pdo = Database::getConnection();
        $isEdit = $studentId !== null;
        $error = null;
        $success = null;

        $promotionsStmt = $pdo->query("
            SELECT id, label
            FROM promotions
            WHERE is_active = 1
            ORDER BY label ASC
        ");
        $promotions = $promotionsStmt->fetchAll();

        $student = [
            'id' => null,
            'nom' => '',
            'prenom' => '',
            'email' => '',
            'formation' => '',
            'promotion_id' => null,
            'status' => 'en_recherche',
            'last_activity' => null,
        ];

        if ($isEdit) {
            $stmt = $pdo->prepare("
                SELECT
                    u.id,
                    u.nom,
                    u.prenom,
                    u.email,
                    sp.formation,
                    sp.promotion_id,
                    sp.status,
                    sp.last_activity
                FROM users u
                INNER JOIN student_profiles sp ON sp.user_id = u.id
                WHERE u.id = :id
                  AND u.role = 'etudiant'
                LIMIT 1
            ");
            $stmt->execute(['id' => $studentId]);
            $existingStudent = $stmt->fetch();

            if (!$existingStudent) {
                http_response_code(404);
                return 'Étudiant introuvable.';
            }

            $student = $existingStudent;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

            $nom = trim((string) ($_POST['nom'] ?? ''));
            $prenom = trim((string) ($_POST['prenom'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $formation = trim((string) ($_POST['formation'] ?? ''));
            $promotionIdRaw = (string) ($_POST['promotion_id'] ?? '');
            $status = trim((string) ($_POST['status'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            $promotionId = ctype_digit($promotionIdRaw) ? (int) $promotionIdRaw : null;
            $allowedStatuses = ['sans_stage', 'en_recherche', 'stage_trouve', 'stage_valide'];

            $student['nom'] = $nom;
            $student['prenom'] = $prenom;
            $student['email'] = $email;
            $student['formation'] = $formation;
            $student['promotion_id'] = $promotionId;
            $student['status'] = $status;

            if ($nom === '' || $prenom === '' || $email === '' || $formation === '') {
                $error = 'Merci de remplir tous les champs obligatoires.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Adresse email invalide.';
            } elseif ($promotionId === null) {
                $error = 'Merci de choisir une promotion.';
            } elseif (!in_array($status, $allowedStatuses, true)) {
                $error = 'Statut invalide.';
            } elseif (!$isEdit && mb_strlen($password) < 8) {
                $error = 'Le mot de passe initial doit contenir au moins 8 caractères.';
            } else {
                $promotionCheck = $pdo->prepare("
                    SELECT id
                    FROM promotions
                    WHERE id = :id AND is_active = 1
                    LIMIT 1
                ");
                $promotionCheck->execute(['id' => $promotionId]);

                if (!$promotionCheck->fetchColumn()) {
                    $error = 'Promotion invalide.';
                } else {
                    if ($isEdit) {
                        $stmt = $pdo->prepare("
                            SELECT id
                            FROM users
                            WHERE email = :email
                              AND id != :id
                            LIMIT 1
                        ");
                        $stmt->execute([
                            'email' => $email,
                            'id' => $studentId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            SELECT id
                            FROM users
                            WHERE email = :email
                            LIMIT 1
                        ");
                        $stmt->execute([
                            'email' => $email,
                        ]);
                    }

                    if ($stmt->fetch()) {
                        $error = 'Cet email est déjà utilisé par un autre compte.';
                    } else {
                        try {
                            $pdo->beginTransaction();

                            if ($isEdit) {
                                $stmt = $pdo->prepare("
                                    UPDATE users
                                    SET nom = :nom,
                                        prenom = :prenom,
                                        email = :email
                                    WHERE id = :id
                                      AND role = 'etudiant'
                                ");
                                $stmt->execute([
                                    'id' => $studentId,
                                    'nom' => $nom,
                                    'prenom' => $prenom,
                                    'email' => $email,
                                ]);

                                $stmt = $pdo->prepare("
                                    UPDATE student_profiles
                                    SET formation = :formation,
                                        promotion_id = :promotion_id,
                                        status = :status,
                                        last_activity = CURDATE()
                                    WHERE user_id = :user_id
                                ");
                                $stmt->execute([
                                    'user_id' => $studentId,
                                    'formation' => $formation,
                                    'promotion_id' => $promotionId,
                                    'status' => $status,
                                ]);

                                $success = 'Profil étudiant mis à jour avec succès.';
                            } else {
                                $stmt = $pdo->prepare("
                                    INSERT INTO users (nom, prenom, email, password_hash, role)
                                    VALUES (:nom, :prenom, :email, :password_hash, 'etudiant')
                                ");
                                $stmt->execute([
                                    'nom' => $nom,
                                    'prenom' => $prenom,
                                    'email' => $email,
                                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                                ]);

                                $newStudentId = (int) $pdo->lastInsertId();

                                $stmt = $pdo->prepare("
                                    INSERT INTO student_profiles (user_id, formation, promotion_id, status, last_activity)
                                    VALUES (:user_id, :formation, :promotion_id, :status, CURDATE())
                                ");
                                $stmt->execute([
                                    'user_id' => $newStudentId,
                                    'formation' => $formation,
                                    'promotion_id' => $promotionId,
                                    'status' => $status,
                                ]);

                                $studentId = $newStudentId;
                                $isEdit = true;
                                $student['id'] = $newStudentId;
                                $student['last_activity'] = date('Y-m-d');
                                $success = 'Étudiant créé avec succès.';
                            }

                            $pdo->commit();

                            $stmt = $pdo->prepare("
                                SELECT
                                    u.id,
                                    u.nom,
                                    u.prenom,
                                    u.email,
                                    sp.formation,
                                    sp.promotion_id,
                                    sp.status,
                                    sp.last_activity
                                FROM users u
                                INNER JOIN student_profiles sp ON sp.user_id = u.id
                                WHERE u.id = :id
                                  AND u.role = 'etudiant'
                                LIMIT 1
                            ");
                            $stmt->execute(['id' => $studentId]);
                            $student = $stmt->fetch();
                        } catch (\Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }

                            $error = $isEdit
                                ? 'Erreur lors de la mise à jour du profil.'
                                : 'Erreur lors de la création de l’étudiant.';
                        }
                    }
                }
            }
        }

        return $this->twig->render('pilot-student-form.html.twig', [
            'student' => $student,
            'promotions' => $promotions,
            'is_edit' => $isEdit,
            'error' => $error,
            'success' => $success,
        ]);
    }

    public function delete(int $studentId): void
    {
        if (
            !isset($_SESSION['user'])
            || !in_array($_SESSION['user']['role'] ?? null, ['pilote', 'administrateur'], true)
        ) {
            header('Location: /connexion');
            exit;
        }

        Csrf::requireValidToken($_POST['_csrf_token'] ?? null);

        $pdo = Database::getConnection();
        $currentUserRole = $_SESSION['user']['role'] ?? null;

        if ($currentUserRole === 'pilote') {
            $pilotId = (int) ($_SESSION['user']['id'] ?? 0);
            if (!PilotPromotionAccess::pilotCanAccessStudent($pdo, $pilotId, $studentId)) {
                http_response_code(403);
                exit('Accès refusé à cet étudiant.');
            }
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('DELETE FROM student_wishlist WHERE user_id = :id');
            $stmt->execute(['id' => $studentId]);

            $stmt = $pdo->prepare('DELETE FROM candidatures WHERE student_user_id = :id');
            $stmt->execute(['id' => $studentId]);

            $stmt = $pdo->prepare('DELETE FROM student_profiles WHERE user_id = :id');
            $stmt->execute(['id' => $studentId]);

            $stmt = $pdo->prepare("
                DELETE FROM users
                WHERE id = :id
                  AND role = 'etudiant'
            ");
            $stmt->execute(['id' => $studentId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        header('Location: /pilot-etudiants');
        exit;
    }
}