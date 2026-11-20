<?php

declare(strict_types=1);

namespace App\Demo;

use App\Entity\Connection;
use App\Entity\ConnectionAuth;
use App\Entity\IncomingEvent;
use App\Entity\Precedent;
use App\Entity\Reflex;
use App\Entity\Run;
use App\Entity\ToolPolicy;
use App\Entity\User;
use App\Entity\Waitpoint;
use App\Mcp\ConnectionSynchronizer;
use App\Repository\ConnectionRepository;
use App\Repository\ReflexRepository;
use App\Repository\UserRepository;
use App\Trigger\AbsenceTrigger;
use App\Trigger\Poller;
use App\Trigger\PollTrigger;
use App\Trigger\SubscriptionTrigger;
use Demo\PolarCoffee\Store;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sets up Polar Coffee Store, the demo company: its people, the connections to its shop and
 * helpdesk, and a reflex for each kind of trigger. Running it again updates them in place.
 */
final readonly class PolarCoffee
{
    public const string SHOP = 'Shop';
    public const string SUPPORT = 'Support';
    public const string WATCHED_TICKET = 'support://tickets/T-501';
    public const array REFLEXES = ['Rescue failed payments', 'Answer ticket replies', 'Chase late shipments', 'Weekly digest', 'Reply to bad reviews'];

    /** Name, email and whether they decide, not only watch. */
    private const array PEOPLE = [
        ['Alice (owner)', 'alice@polarcoffee.test', true],
        ['Bob (support lead)', 'bob@polarcoffee.test', true],
        ['Vera (viewer)', 'vera@polarcoffee.test', false],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private ConnectionRepository $connections,
        private ReflexRepository $reflexes,
        private ConnectionSynchronizer $synchronizer,
        private Poller $poller,
        private UserPasswordHasherInterface $hasher,
        #[Autowire('%env(DEMO_MCP_URL)%')]
        private string $demoUrl,
    ) {
    }

    /**
     * @return array{people: list<User>, connections: list<Connection>, reflexes: list<Reflex>}
     */
    public function install(string $password, bool $resetData = false): array
    {
        if ($resetData) {
            Store::fromEnvironment()->reset();
        }

        $people = [];
        foreach (self::PEOPLE as [$name, $email, $admin]) {
            $user = $this->users->findOneByEmail($email) ?? new User($email, $name, $admin);
            $user->setAdmin($admin);
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $this->entityManager->persist($user);
            $people[] = $user;
        }

        $shop = $this->connection(self::SHOP, rtrim($this->demoUrl, '/').'/shop/mcp');
        $support = $this->connection(self::SUPPORT, rtrim($this->demoUrl, '/').'/support/mcp');
        $this->entityManager->flush();

        foreach ([$shop, $support] as $connection) {
            $this->synchronizer->synchronize($connection);
        }

        if ($resetData) {
            $this->forgetHistory();
        }

        $reflexes = [];
        $polls = [];
        foreach ($this->definitions($shop, $support) as $definition) {
            $reflex = $this->reflex(...$definition);
            $reflexes[] = $reflex;
            if (null !== $reflex->getPollTrigger()) {
                $polls[] = $reflex;
            }
        }
        $this->entityManager->flush();

        // The first poll only takes a baseline: taking it now makes the next new review fire.
        foreach ($polls as $reflex) {
            $this->poller->poll($reflex);
        }

        return ['people' => $people, 'connections' => [$shop, $support], 'reflexes' => $reflexes];
    }

    /**
     * @return iterable<array{0: string, 1: string, 2: string, 3: string, 4: list<Connection>, 5: array<string, ToolPolicy>, 6: array<string, int>, 7?: PollTrigger|AbsenceTrigger|SubscriptionTrigger|null, 8?: string|null}>
     */
    private function definitions(Connection $shop, Connection $support): iterable
    {
        $read = ToolPolicy::Auto;
        $ask = ToolPolicy::Approval;
        $never = ToolPolicy::Forbidden;

        yield [
            'Rescue failed payments', 'generic', 'payment.failed',
            <<<'TXT'
                A customer's payment failed.

                1. Look them up with `shop_find_customer` and their tickets with `support_find_tickets`.
                2. Then propose:
                   - for a **loyal customer** (a subscriber, or over 100 EUR lifetime value), a 10% coupon with a warm note;
                   - for anyone else, opening a ticket that asks them to update their card.

                **Never** retry a payment declined for insufficient funds: it fails again.
                TXT,
            [$shop, $support],
            ['shop_find_customer' => $read, 'shop_get_order' => $read, 'support_find_tickets' => $read, 'shop_issue_coupon' => $ask, 'shop_retry_payment' => $ask, 'support_open_ticket' => $ask, 'shop_refund_order' => $ask],
            [],
        ];

        yield [
            'Answer ticket replies', Reflex::SUBSCRIPTION_SOURCE, Reflex::ANY_EVENT,
            <<<'TXT'
                The customer wrote on a support ticket; its new contents are in the event.

                1. Read the order it mentions with `shop_get_order`.
                2. Propose a reply with `support_reply_ticket`.
                3. If coffee arrived **damaged or missing**, also propose refunding those items with `shop_refund_order`.
                   The person approving picks card or store credit.
                TXT,
            [$support, $shop],
            ['support_get_ticket' => $read, 'support_tag_ticket' => $read, 'shop_get_order' => $read, 'shop_find_customer' => $read, 'support_reply_ticket' => $ask, 'shop_refund_order' => $ask],
            [],
            new SubscriptionTrigger($support->getId()->toRfc4122(), [self::WATCHED_TICKET], listChanged: false),
        ];

        yield [
            'Chase late shipments', Reflex::ABSENCE_SOURCE, Reflex::ANY_EVENT,
            <<<'TXT'
                An order was paid but has not shipped in time.

                1. Look it up with `shop_get_order`.
                2. Propose opening a ticket that apologises and says it ships within a day.
                3. For a **subscriber**, also propose a 10% coupon.
                TXT,
            [$shop, $support],
            ['shop_get_order' => $read, 'shop_find_customer' => $read, 'support_open_ticket' => $ask, 'shop_issue_coupon' => $ask],
            [],
            new AbsenceTrigger('generic', 'order.paid', 'order.shipped', 2, 'order_id'),
        ];

        yield [
            'Weekly digest', Reflex::SCHEDULE_SOURCE, Reflex::ANY_EVENT,
            <<<'TXT'
                Write the weekly digest for the owners with:

                - orders and revenue, from `shop_list_orders`;
                - open tickets, from `support_list_tickets`;
                - recent reviews, from `shop_list_reviews`.

                End with up to three suggested actions. **Change nothing.**
                TXT,
            [$shop, $support],
            ['shop_list_orders' => $read, 'shop_list_reviews' => $read, 'support_list_tickets' => $read, 'shop_issue_coupon' => $never, 'shop_refund_order' => $never, 'shop_retry_payment' => $never, 'shop_reply_review' => $never, 'support_reply_ticket' => $never, 'support_open_ticket' => $never],
            [],
            null,
            '0 9 * * 1',
        ];

        yield [
            'Reply to bad reviews', Reflex::POLL_SOURCE, Reflex::ANY_EVENT,
            <<<'TXT'
                New reviews arrived.

                - **4 or 5 stars**: ignore them.
                - **3 stars or fewer**: propose a public reply with `shop_reply_review`. Thank them and own the problem, no excuses.
                - **1 or 2 stars**: also look the customer up and propose a 15% coupon.
                TXT,
            [$shop],
            ['shop_list_reviews' => $read, 'shop_find_customer' => $read, 'shop_reply_review' => $ask, 'shop_issue_coupon' => $ask],
            [],
            new PollTrigger($shop->getId()->toRfc4122(), 'list_reviews', ['limit' => 10], 1, 'id'),
        ];
    }

    /**
     * Back to before anything happened: the demo reflexes' runs and decisions, their memory,
     * and the events nothing refers to any more.
     */
    private function forgetHistory(): void
    {
        $reflexes = $this->reflexes->findBy(['name' => self::REFLEXES]);
        if ([] === $reflexes) {
            return;
        }

        foreach ([Run::class, Waitpoint::class, Precedent::class] as $class) {
            $this->entityManager->createQueryBuilder()->delete($class, 'x')->where('x.reflex IN (:reflexes)')->setParameter('reflexes', $reflexes)->getQuery()->execute();
        }
        $this->entityManager->createQueryBuilder()
            ->delete(IncomingEvent::class, 'e')
            ->where(\sprintf('NOT EXISTS (SELECT 1 FROM %s r WHERE r.event = e) AND NOT EXISTS (SELECT 1 FROM %s w WHERE w.event = e)', Run::class, Waitpoint::class))
            ->getQuery()
            ->execute();
        foreach ($reflexes as $reflex) {
            $reflex->forgetPoll();
        }
    }

    private function connection(string $name, string $url): Connection
    {
        $connection = $this->connections->findOneBy(['name' => $name]);
        if (null === $connection || $connection->getUrl() !== $url) {
            if (null !== $connection) {
                $this->entityManager->remove($connection);
                $this->entityManager->flush();
            }
            $connection = new Connection($name, $url, ConnectionAuth::None);
            $this->entityManager->persist($connection);
        }

        return $connection;
    }

    /**
     * @param list<Connection>          $connections
     * @param array<string, ToolPolicy> $policies
     * @param array<string, int>        $approvals
     */
    private function reflex(string $name, string $source, string $event, string $instructions, array $connections, array $policies, array $approvals, PollTrigger|AbsenceTrigger|SubscriptionTrigger|null $trigger = null, ?string $cron = null): Reflex
    {
        $reflex = $this->reflexes->findOneBy(['name' => $name]) ?? new Reflex($name, $source, $event, $instructions);
        $reflex->update($name, $source, $event, $instructions, Reflex::DEFAULT_MODEL, true, $connections);
        $reflex->setToolPolicies($policies, $approvals);
        $reflex->setTriggerConfig($trigger);
        $reflex->setCron($cron);
        $this->entityManager->persist($reflex);

        return $reflex;
    }
}
