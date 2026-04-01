<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Repository\CompanyCommentRepository;
use App\Repository\CompanyRepository;
use Twig\Environment;

class CompanyCommentController
{
    private Environment $twig;
    private CompanyRepository $companyRepository;
    private CompanyCommentRepository $companyCommentRepository;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;

        $pdo = Database::getConnection();
        $this->companyRepository = new CompanyRepository($pdo);
        $this->companyCommentRepository = new CompanyCommentRepository($pdo);
    }

    public function index(int $companyId): string
    {
        $company = $this->companyRepository->findById($companyId);

        if (!$company) {
            http_response_code(404);
            return 'Entreprise introuvable.';
        }

        $comments = $this->companyCommentRepository->findByCompanyId($companyId);

        return $this->twig->render('company-comments.html.twig', [
            'company' => $company,
            'comments' => $comments,
        ]);
    }
}