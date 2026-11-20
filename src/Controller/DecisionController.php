<?php

declare(strict_types=1);

namespace App\Controller;

use App\Engine\DecisionClosedException;
use App\Engine\DecisionDesk;
use App\Entity\Decision;
use App\Entity\User;
use App\Form\QuestionFormFactory;
use App\Memory\Memory;
use App\Repository\DecisionRepository;
use App\Security\DecisionVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DecisionController extends AbstractController
{
    private const string CSRF_ID = '"decide-" ~ args["decision"].getId()';

    public function __construct(
        private readonly DecisionRepository $decisions,
        private readonly DecisionDesk $desk,
        private readonly QuestionFormFactory $questionForms,
        private readonly Memory $memory,
    ) {
    }

    #[Route('/decisions', name: 'app_decision_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('decision/index.html.twig', [
            'pending' => $this->decisions->findPending(),
            'decided' => $this->decisions->findRecentlyDecided(),
        ]);
    }

    #[Route('/decisions/{id}', name: 'app_decision_show', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function show(Request $request, Decision $decision, #[CurrentUser] User $user): Response
    {
        $question = $decision->getQuestion();
        $form = null;
        if (null !== $question && $this->isGranted(DecisionVoter::ANSWER, $decision)) {
            $form = $this->questionForms->create($question);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                return $this->act($decision, function () use ($decision, $user, $question, $form): void {
                    $this->desk->answer($decision, $user, $this->questionForms->content($question, $form));
                    $this->addFlash('success', 'Answered. The call is repeated with your answer.');
                });
            }
        }

        return $this->render('decision/show.html.twig', [
            'decision' => $decision,
            'answer_form' => $form,
            'precedents' => $decision->isPending() ? $this->memory->recallFor($decision) : [],
        ]);
    }

    #[Route('/decisions/{id}/approve', name: 'app_decision_approve', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression(self::CSRF_ID))]
    #[IsGranted(DecisionVoter::DECIDE, subject: 'decision')]
    public function approve(Request $request, Decision $decision, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->act($decision, function () use ($request, $decision, $user): void {
            $approved = $this->desk->approve($decision, $user, $this->note($request));
            $missing = $decision->getRequiredApprovals() - $decision->countApprovals();
            $this->addFlash('success', $approved
                ? 'Approved. It runs exactly as shown.'
                : \sprintf('Your approval is recorded. It needs %d more.', $missing));
        });
    }

    #[Route('/decisions/{id}/reject', name: 'app_decision_reject', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression(self::CSRF_ID))]
    #[IsGranted(DecisionVoter::DECIDE, subject: 'decision')]
    public function reject(Request $request, Decision $decision, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->act($decision, function () use ($request, $decision, $user): void {
            $this->desk->reject($decision, $user, $this->note($request));
            $this->addFlash('success', 'Rejected. The agent is told why.');
        });
    }

    #[Route('/decisions/{id}/decline', name: 'app_decision_decline', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression(self::CSRF_ID))]
    #[IsGranted(DecisionVoter::ANSWER, subject: 'decision')]
    public function decline(Request $request, Decision $decision, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->act($decision, function () use ($request, $decision, $user): void {
            $this->desk->decline($decision, $user, $this->note($request));
            $this->addFlash('success', 'Declined. The call is not repeated and the agent is told.');
        });
    }

    /**
     * @param callable(): void $action
     */
    private function act(Decision $decision, callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (DecisionClosedException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_decision_show', ['id' => $decision->getId()]);
    }

    private function note(Request $request): ?string
    {
        $note = trim($request->getPayload()->getString('note'));

        return '' === $note ? null : mb_substr($note, 0, 2000);
    }
}
