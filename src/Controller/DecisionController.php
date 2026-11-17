<?php

declare(strict_types=1);

namespace App\Controller;

use App\Engine\DecisionDesk;
use App\Entity\Decision;
use App\Repository\DecisionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class DecisionController extends AbstractController
{
    public function __construct(
        private readonly DecisionRepository $decisions,
        private readonly DecisionDesk $desk,
    ) {
    }

    #[Route('/inbox', name: 'app_inbox', methods: ['GET'])]
    public function inbox(): Response
    {
        return $this->render('decision/inbox.html.twig', [
            'pending' => $this->decisions->findPending(),
            'decided' => $this->decisions->findRecentlyDecided(),
        ]);
    }

    #[Route('/decisions/{id}', name: 'app_decision_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(Decision $decision): Response
    {
        return $this->render('decision/show.html.twig', ['decision' => $decision]);
    }

    #[Route('/decisions/{id}/approve', name: 'app_decision_approve', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function approve(Request $request, Decision $decision): RedirectResponse
    {
        if ($this->guard($request, $decision)) {
            $this->desk->approve($decision, $this->note($request));
            $this->addFlash('success', 'Approved. It runs exactly as shown.');
        }

        return $this->redirectToRoute('app_decision_show', ['id' => $decision->getId()]);
    }

    #[Route('/decisions/{id}/reject', name: 'app_decision_reject', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function reject(Request $request, Decision $decision): RedirectResponse
    {
        if ($this->guard($request, $decision)) {
            $this->desk->reject($decision, $this->note($request));
            $this->addFlash('success', 'Rejected. The agent is told why.');
        }

        return $this->redirectToRoute('app_decision_show', ['id' => $decision->getId()]);
    }

    private function guard(Request $request, Decision $decision): bool
    {
        if (!$this->isCsrfTokenValid('decide-'.$decision->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$decision->isPending()) {
            $this->addFlash('error', 'Someone already decided on it.');

            return false;
        }

        return true;
    }

    private function note(Request $request): ?string
    {
        $note = trim($request->getPayload()->getString('note'));

        return '' === $note ? null : mb_substr($note, 0, 2000);
    }
}
