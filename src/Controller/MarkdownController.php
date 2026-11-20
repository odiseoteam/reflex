<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\MarkdownPreview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class MarkdownController extends AbstractController
{
    #[Route('/markdown/preview', name: 'app_markdown_preview', methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function preview(#[MapRequestPayload] MarkdownPreview $preview = new MarkdownPreview()): Response
    {
        return $this->render('markdown/preview.html.twig', ['text' => $preview->text]);
    }
}
