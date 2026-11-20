<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Run;
use App\Form\RunFilter;
use App\Repository\DecisionRepository;
use App\Repository\PrecedentRepository;
use App\Repository\ReflexRepository;
use App\Repository\RunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/runs')]
final class RunController extends AbstractController
{
    public function __construct(
        private readonly RunRepository $runs,
        private readonly ReflexRepository $reflexes,
        private readonly DecisionRepository $decisions,
        private readonly PrecedentRepository $precedents,
    ) {
    }

    #[Route('', name: 'app_run_index', methods: ['GET'])]
    public function index(#[MapQueryString] RunFilter $filter = new RunFilter()): Response
    {
        $reflex = null === $filter->reflex ? null : $this->reflexes->find($filter->reflex);
        $runs = $this->runs->findFiltered($filter->status, $reflex);

        return $this->render('run/index.html.twig', [
            'runs' => $runs,
            'steps' => $this->runs->countStepsByKind($runs),
            'filter' => $filter,
            'reflexes' => $this->reflexes->findAllOrdered(),
        ]);
    }

    #[Route('/{id}', name: 'app_run_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(Run $run): Response
    {
        return $this->render('run/show.html.twig', [
            'run' => $run,
            'decisions' => $this->decisions->findByRun($run),
            'recalled' => $this->precedents->findInOrder($run->getRecalled()),
        ]);
    }
}
