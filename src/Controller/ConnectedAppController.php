<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\OAuthGrant;
use App\Entity\User;
use App\OAuth\Endpoints;
use App\Repository\OAuthGrantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * The apps a person connected to Reflex's MCP server, and how to connect one.
 */
#[Route('/apps')]
final class ConnectedAppController extends AbstractController
{
    public function __construct(
        private readonly OAuthGrantRepository $grants,
        private readonly EntityManagerInterface $entityManager,
        private readonly Endpoints $endpoints,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'app_connected_apps', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render('oauth/apps.html.twig', [
            'grants' => $this->grants->findActive($user, $this->clock->now()),
            'mcp_url' => $this->endpoints->resource(),
        ]);
    }

    #[Route('/{id}/disconnect', name: 'app_connected_app_disconnect', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"disconnect-" ~ args["grant"].getId()'))]
    public function disconnect(OAuthGrant $grant, #[CurrentUser] User $user): RedirectResponse
    {
        if ($grant->getUser() !== $user) {
            throw $this->createNotFoundException();
        }

        $grant->revoke();
        $this->entityManager->flush();
        $this->addFlash('success', \sprintf('%s is disconnected.', $grant->getClient()->getName()));

        return $this->redirectToRoute('app_connected_apps');
    }
}
