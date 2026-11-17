<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Reflex;
use App\Entity\Run;
use App\Entity\ToolPolicy;
use App\Form\ReflexInput;
use App\Form\ReflexType;
use App\Message\RunReflex;
use App\Repository\DecisionRepository;
use App\Repository\ReflexRepository;
use App\Repository\RunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/reflexes')]
final class ReflexController extends AbstractController
{
    public function __construct(
        private readonly ReflexRepository $reflexes,
        private readonly RunRepository $runs,
        private readonly DecisionRepository $decisions,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('', name: 'app_reflex_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('reflex/index.html.twig', [
            'reflexes' => $this->reflexes->findAllOrdered(),
            'runs' => $this->runs->findLatest(limit: 20),
        ]);
    }

    #[Route('/new', name: 'app_reflex_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $input = new ReflexInput();
        $form = $this->createForm(ReflexType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $reflex = new Reflex($input->name, $input->triggerSource, $input->triggerEvent, $input->instructions, $input->model);
            $input->applyTo($reflex);
            $this->entityManager->persist($reflex);
            $this->entityManager->flush();

            return $this->redirectToRoute('app_reflex_show', ['id' => $reflex->getId()]);
        }

        return $this->render('reflex/form.html.twig', ['form' => $form, 'reflex' => null]);
    }

    #[Route('/{id}', name: 'app_reflex_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(Reflex $reflex): Response
    {
        return $this->render('reflex/show.html.twig', [
            'reflex' => $reflex,
            'tools' => $this->catalog($reflex),
            'policies' => ToolPolicy::cases(),
            'runs' => $this->runs->findLatest($reflex),
            'tokensToday' => $this->runs->tokensUsedToday($reflex),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_reflex_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(Request $request, Reflex $reflex): Response
    {
        $input = ReflexInput::fromReflex($reflex);
        $form = $this->createForm(ReflexType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $input->applyTo($reflex);
            $this->entityManager->flush();

            return $this->redirectToRoute('app_reflex_show', ['id' => $reflex->getId()]);
        }

        return $this->render('reflex/form.html.twig', ['form' => $form, 'reflex' => $reflex]);
    }

    #[Route('/{id}/policy', name: 'app_reflex_policy', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function policy(Request $request, Reflex $reflex): RedirectResponse
    {
        $this->denyUnlessCsrfValid($request, 'policy-'.$reflex->getId());

        $submitted = $request->getPayload()->all('policies');
        $policies = [];
        foreach ($this->catalog($reflex) as $tool) {
            $value = $submitted[$tool['name']] ?? null;
            $policy = \is_string($value) ? ToolPolicy::tryFrom($value) : null;
            if (null !== $policy) {
                $policies[$tool['name']] = $policy;
            }
        }

        $reflex->setToolPolicies($policies);
        $this->entityManager->flush();
        $this->addFlash('success', 'Policy saved.');

        return $this->redirectToRoute('app_reflex_show', ['id' => $reflex->getId()]);
    }

    #[Route('/{id}/run', name: 'app_reflex_run', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function run(Request $request, Reflex $reflex): RedirectResponse
    {
        $this->denyUnlessCsrfValid($request, 'run-'.$reflex->getId());

        $run = new Run($reflex);
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $this->bus->dispatch(new RunReflex($run->getId()->toRfc4122()));

        return $this->redirectToRoute('app_run_show', ['id' => $run->getId()]);
    }

    #[Route('/runs/{id}', name: 'app_run_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function showRun(Run $run): Response
    {
        return $this->render('reflex/run.html.twig', ['run' => $run, 'decisions' => $this->decisions->findByRun($run)]);
    }

    /**
     * Tools of the reflex's connections, as the agent names them, with their effective policy.
     *
     * @return list<array{name: string, description: string, connection: string, annotations: array<string, mixed>, policy: ToolPolicy, configured: bool}>
     */
    private function catalog(Reflex $reflex): array
    {
        $configured = $reflex->getToolPolicies();
        $tools = [];
        foreach ($reflex->getConnections() as $connection) {
            foreach ($connection->getTools() as $tool) {
                $name = $connection->getSlug().'_'.(\is_string($tool['name'] ?? null) ? $tool['name'] : '');
                /** @var array<string, mixed> $annotations */
                $annotations = \is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];
                $tools[] = [
                    'name' => $name,
                    'description' => \is_string($tool['description'] ?? null) ? $tool['description'] : '',
                    'connection' => $connection->getName(),
                    'annotations' => $annotations,
                    'policy' => $reflex->policyFor($name, $annotations),
                    'configured' => isset($configured[$name]),
                ];
            }
        }

        return $tools;
    }

    private function denyUnlessCsrfValid(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
