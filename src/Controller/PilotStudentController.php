<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\StudentRepository;
use App\Security\Csrf;
use App\Support\PilotPromotionAccess;
use Twig\Environment;

class PilotStudentController
{
    private Environment $twig;
    private StudentRepository $studentRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
        $this->studentRepository = new StudentRepository(Database::getConnection());
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
            $promotions = $this->studentRepository->getAllPromotions();
        }

        $totalStudents = $this->studentRepository->countStudents(
            $currentUserRole,
            $allowedPromotionIds,
            $selectedPromotionId,
            $search
        );

        $totalPages = max(1, (int) ceil($totalStudents / $perPage));
        if ($currentPage > $totalPages) {
            $currentPage = $totalPages;
        }

        $offset = ($currentPage - 1) * $perPage;

        $students = $this->studentRepository->findStudentsPaginated(
            $currentUserRole,
            $allowedPromotionIds,
            $selectedPromotionId,
            $search,
            $perPage,
            $offset
        );

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

        $student = $this->studentRepository->findStudentById($studentId);

        if (!$student) {
            http_response_code(404);
            return 'Étudiant introuvable.';
        }

        $applications = $this->studentRepository->findApplicationsByStudentId($studentId);

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

        $isEdit = $studentId !== null;
        $error = null;
        $success = null;

        $promotions = $this->studentRepository->getActivePromotions();

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
            $existingStudent = $this->studentRepository->findStudentForEdit($studentId);

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
            } elseif (mb_strlen($nom) > 100 || mb_strlen($prenom) > 100) {
                $error = 'Le nom et le prénom ne doivent pas dépasser 100 caractères.';
            } elseif (mb_strlen($email) > 255) {
                $error = "L'email ne doit pas dépasser 255 caractères.";
            } elseif (mb_strlen($formation) > 150) {
                $error = 'La formation ne doit pas dépasser 150 caractères.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Adresse email invalide.';
            } elseif ($promotionId === null) {
                $error = 'Merci de choisir une promotion.';
            } elseif (!in_array($status, $allowedStatuses, true)) {
                $error = 'Statut invalide.';
            } elseif (!$isEdit && mb_strlen($password) < 8) {
                $error = 'Le mot de passe initial doit contenir au moins 8 caractères.';
            } elseif (!$this->studentRepository->isActivePromotion($promotionId)) {
                $error = 'Promotion invalide.';
            } elseif ($this->studentRepository->emailExists($email, $isEdit ? $studentId : null)) {
                $error = 'Cet email est déjà utilisé par un autre compte.';
            } else {
                try {
                    if ($isEdit) {
                        $this->studentRepository->updateStudent(
                            $studentId,
                            $nom,
                            $prenom,
                            $email,
                            $formation,
                            $promotionId,
                            $status
                        );

                        $success = 'Profil étudiant mis à jour avec succès.';
                    } else {
                        $studentId = $this->studentRepository->createStudent(
                            $nom,
                            $prenom,
                            $email,
                            $password,
                            $formation,
                            $promotionId,
                            $status
                        );

                        $isEdit = true;
                        $student['id'] = $studentId;
                        $student['last_activity'] = date('Y-m-d');
                        $success = 'Étudiant créé avec succès.';
                    }

                    $student = $this->studentRepository->findStudentForEdit($studentId);
                } catch (\Throwable $e) {
                    $error = $isEdit
                        ? 'Erreur lors de la mise à jour du profil.'
                        : 'Erreur lors de la création de l’étudiant.';
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
            $this->studentRepository->deleteStudent($studentId);
        } catch (\Throwable $e) {
            error_log('[PilotStudentController::delete] Erreur suppression étudiant #' . $studentId . ' : ' . $e->getMessage());
            header('Location: /pilot-etudiants?error=suppression_echouee');
            exit;
        }

        header('Location: /pilot-etudiants');
        exit;
    }
}