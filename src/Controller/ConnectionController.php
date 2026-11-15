<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Connection;
use App\Entity\ConnectionAuth;
use App\Form\ConnectionInput;
use App\Form\ConnectionType;
use App\Mcp\ConnectionSynchronizer;
use App\Mcp\McpClientFactory;
use App\Repository\ConnectionRepository;
use App\Security\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Client\Exception\AuthorizationException;
use Mcp\Client\Exception\AuthorizationPendingException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ConnectionController extends AbstractController
{
    /** Session key of the connection whose authorization is in progress. */
    private const string PENDING_CONNECTION = 'reflex.oauth_connection';

    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly ConnectionSynchronizer $synchronizer,
        private readonly McpClientFactory $clientFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretBox $secretBox,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): RedirectResponse
    {
        return $this->redirectToRoute('app_reflex_index');
    }

    #[Route('/connections', name: 'app_connection_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('connection/index.html.twig', [
            'connections' => $this->connections->findAllOrdered(),
        ]);
    }

    #[Route('/connections/new', name: 'app_connection_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $input = new ConnectionInput();
        $form = $this->createForm(ConnectionType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (null !== $this->connections->findOneBy(['slug' => Connection::slugify($input->name)])) {
                $form->get('name')->addError(new FormError('Another connection already uses this name.'));
            } else {
                $connection = $this->createConnection($input);
                $this->entityManager->persist($connection);
                $this->entityManager->flush();

                return $this->synchronize($request, $connection);
            }
        }

        return $this->render('connection/new.html.twig', ['form' => $form]);
    }

    #[Route('/connections/{id}', name: 'app_connection_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(Connection $connection): Response
    {
        return $this->render('connection/show.html.twig', ['connection' => $connection]);
    }

    #[Route('/connections/{id}/sync', name: 'app_connection_sync', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function sync(Request $request, Connection $connection): Response
    {
        $this->denyUnlessCsrfValid($request, 'sync-'.$connection->getId());

        return $this->synchronize($request, $connection);
    }

    #[Route('/connections/{id}/delete', name: 'app_connection_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function delete(Request $request, Connection $connection): RedirectResponse
    {
        $this->denyUnlessCsrfValid($request, 'delete-'.$connection->getId());

        $this->entityManager->remove($connection);
        $this->entityManager->flush();
        $this->addFlash('success', \sprintf('Connection "%s" removed.', $connection->getName()));

        return $this->redirectToRoute('app_connection_index');
    }

    #[Route('/connections/oauth/callback', name: 'app_connection_oauth_callback', methods: ['GET'])]
    public function oauthCallback(Request $request): Response
    {
        // The connection comes from the session, never from the callback (see the SDK docs).
        $id = $request->getSession()->remove(self::PENDING_CONNECTION);
        $connection = \is_string($id) ? $this->connections->find($id) : null;
        if (null === $connection) {
            $this->addFlash('error', 'No authorization in progress. Start again from the connection page.');

            return $this->redirectToRoute('app_connection_index');
        }

        $query = $request->query->all();
        if (isset($query['error'])) {
            $connection->markFailed(\sprintf('Authorization refused: %s', $request->query->getString('error_description', $request->query->getString('error'))));
            $this->entityManager->flush();

            return $this->redirectToRoute('app_connection_show', ['id' => $connection->getId()]);
        }

        try {
            $params = array_filter($query, static fn (mixed $value): bool => \is_string($value));
            $this->clientFactory->createAuthenticator($connection)->finishAuthorization($params);
        } catch (AuthorizationPendingException $e) {
            return $this->redirectToAuthorization($request, $connection, $e);
        } catch (AuthorizationException $e) {
            $connection->markFailed('Authorization failed: '.$e->getMessage());
            $this->entityManager->flush();

            return $this->redirectToRoute('app_connection_show', ['id' => $connection->getId()]);
        }

        return $this->synchronize($request, $connection);
    }

    private function denyUnlessCsrfValid(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function synchronize(Request $request, Connection $connection): Response
    {
        try {
            $this->synchronizer->synchronize($connection);
        } catch (AuthorizationPendingException $e) {
            return $this->redirectToAuthorization($request, $connection, $e);
        }

        if (null === $connection->getLastError()) {
            $this->addFlash('success', \sprintf('Connected to "%s": %d tools.', $connection->getName(), \count($connection->getTools())));
        }

        return $this->redirectToRoute('app_connection_show', ['id' => $connection->getId()]);
    }

    private function redirectToAuthorization(Request $request, Connection $connection, AuthorizationPendingException $e): RedirectResponse
    {
        $request->getSession()->set(self::PENDING_CONNECTION, $connection->getId()->toRfc4122());

        return new RedirectResponse($e->authorizationUrl);
    }

    private function createConnection(ConnectionInput $input): Connection
    {
        $connection = new Connection($input->name, $input->url, $input->auth);

        if (ConnectionAuth::Bearer === $input->auth && null !== $input->token) {
            $connection->setEncryptedSecret($this->secretBox->encrypt($input->token));
        }

        if (ConnectionAuth::OAuth === $input->auth && null !== $input->oauthClientId && null !== $input->oauthIssuer) {
            $connection->usePreRegisteredClient($input->oauthClientId, $input->oauthIssuer);
            if (null !== $input->oauthClientSecret) {
                $connection->setEncryptedSecret($this->secretBox->encrypt($input->oauthClientSecret));
            }
        }

        return $connection;
    }
}
