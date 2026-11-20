<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dashboard\Dashboard;
use App\Demo\Scenarios;
use App\Repository\DecisionRepository;
use App\Repository\RunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    private const int LATEST_RUNS = 10;

    public function __construct(
        private readonly Dashboard $dashboard,
        private readonly DecisionRepository $decisions,
        private readonly RunRepository $runs,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        $reflexes = $this->dashboard->reflexes();
        $runs = $this->runs->findLatest(limit: self::LATEST_RUNS);

        return $this->render('home/index.html.twig', [
            'stats' => $this->dashboard->stats($reflexes),
            'enabled_reflexes' => \count(array_filter($reflexes, static fn ($reflex): bool => $reflex->isEnabled())),
            'pending' => $this->decisions->findPending(),
            'runs' => $runs,
            'steps' => $this->runs->countStepsByKind($runs),
            'scenarios' => Scenarios::ALL,
            'recorded_customers' => Scenarios::RECORDED_CUSTOMERS,
        ]);
    }
}
