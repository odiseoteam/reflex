<?php

declare(strict_types=1);

namespace App\Controller;

use App\Demo\Scenarios;
use App\Entity\User;
use App\Form\Simulation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SimulationController extends AbstractController
{
    public function __construct(
        private readonly Scenarios $scenarios,
    ) {
    }

    #[Route('/simulate', name: 'app_simulate', methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    #[IsCsrfTokenValid('simulate')]
    public function simulate(#[MapRequestPayload] Simulation $simulation): RedirectResponse
    {
        try {
            $this->addFlash('success', $this->scenarios->play($simulation->scenario, $simulation->customer));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_home');
    }
}
