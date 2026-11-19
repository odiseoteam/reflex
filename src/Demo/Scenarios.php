<?php

declare(strict_types=1);

namespace App\Demo;

use App\Engine\EventIntake;
use App\Entity\Run;
use App\Message\RunReflex;
use App\Repository\ReflexRepository;
use Demo\PolarCoffee\Store;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What happens at Polar Coffee on demand: the shop changes its data and sends the webhook
 * a real shop would, or the helpdesk notifies a subscribed ticket.
 */
final readonly class Scenarios
{
    public const array ALL = [
        'payment-failed' => 'Ana\'s subscription renewal is declined (webhook payment.failed). Use --customer=bruno for a new customer.',
        'customer-replies' => 'Carla replies on ticket T-501 with a photo of the torn bag (subscription notification).',
        'order-paid' => 'Bruno pays an order (webhook order.paid). Unless order-shipped follows within 2 minutes, "Paid but not shipped" runs.',
        'order-shipped' => 'The latest paid order ships (webhook order.shipped).',
        'bad-review' => 'A 1-star review is posted; "Review watch" finds it on its next poll, within a minute.',
        'weekly-digest' => 'Runs the weekly digest now instead of on Monday.',
    ];

    private const array CUSTOMERS = ['ana' => 'cus_ana', 'bruno' => 'cus_bruno', 'carla' => 'cus_carla'];

    public function __construct(
        private EventIntake $intake,
        private ReflexRepository $reflexes,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @return string what happened
     */
    public function play(string $scenario, string $customer = 'ana'): string
    {
        $store = Store::fromEnvironment();

        return match ($scenario) {
            'payment-failed' => $this->paymentFailed($store, self::CUSTOMERS[$customer] ?? throw new \InvalidArgumentException(\sprintf('Customer is one of: %s.', implode(', ', array_keys(self::CUSTOMERS))))),
            'customer-replies' => $this->customerReplies($store),
            'order-paid' => $this->orderPaid($store),
            'order-shipped' => $this->orderShipped($store),
            'bad-review' => $this->badReview($store),
            'weekly-digest' => $this->runNow('Weekly digest'),
            default => throw new \InvalidArgumentException(\sprintf('Unknown scenario "%s". Use --list.', $scenario)),
        };
    }

    private function paymentFailed(Store $store, string $customerId): string
    {
        [$order, $customer] = $store->change(static function (array $data) use ($customerId): array {
            $customer = Store::find($data['customers'], 'id', $customerId) ?? throw new \LogicException('Unknown customer.');
            $subscriber = true === $customer['subscriber'];
            $order = [
                'id' => self::nextOrderId($data),
                'customer' => $customerId,
                'items' => [['sku' => $subscriber ? 'SUB-MONTH' : 'ESP-1KG', 'qty' => 1]],
                'total' => $subscriber ? 25.0 : 38.0,
                'status' => 'payment_failed',
                'created_at' => Store::now(),
            ];
            $data['orders'][] = $order;
            $data['payments'][] = ['id' => 'pay_'.random_int(9000, 9999), 'order' => $order['id'], 'amount' => $order['total'], 'status' => 'failed', 'failure' => 'card_declined: insufficient_funds', 'at' => Store::now()];

            return [$data, [$order, $customer]];
        });

        $this->webhook('payment.failed', [
            'order_id' => $order['id'],
            'amount' => $order['total'],
            'currency' => 'EUR',
            'failure' => 'card_declined: insufficient_funds',
            'customer' => ['name' => $customer['name'], 'email' => $customer['email']],
        ]);

        return \sprintf('%s: the payment of %s (%.2f EUR) failed. "Failed payments" runs.', Store::text($customer, 'name'), Store::text($order, 'id'), Store::amount($order, 'total'));
    }

    private function customerReplies(Store $store): string
    {
        $store->change(static function (array $data): array {
            $data['tickets'] = Store::update($data['tickets'], 'id', 'T-501', static function (array $ticket): array {
                $ticket['messages'] = [...Store::rows($ticket, 'messages'), ['from' => 'customer', 'text' => 'Here is the photo: the bag split along the side seam and roughly 300 g of beans were loose in the box. The rest is fine.', 'at' => Store::now()]];
                $ticket['status'] = 'open';

                return $ticket;
            });

            return [$data, null];
        });
        $store->publish(new ResourceUpdatedNotification(PolarCoffee::WATCHED_TICKET));

        return 'Carla replied on T-501. The helpdesk notifies the subscription and "Ticket follow-up" runs (the listener service must be up).';
    }

    private function orderPaid(Store $store): string
    {
        $order = $store->change(static function (array $data): array {
            $order = ['id' => self::nextOrderId($data), 'customer' => 'cus_bruno', 'items' => [['sku' => 'ETH-250', 'qty' => 2]], 'total' => 28.0, 'status' => 'paid', 'created_at' => Store::now()];
            $data['orders'][] = $order;
            $data['payments'][] = ['id' => 'pay_'.random_int(9000, 9999), 'order' => $order['id'], 'amount' => 28.0, 'status' => 'succeeded', 'at' => Store::now()];

            return [$data, $order];
        });

        $this->webhook('order.paid', ['order_id' => $order['id'], 'amount' => 28.0, 'customer' => ['email' => 'bruno@example.com']]);

        return \sprintf('Bruno paid %s. Ship it with "order-shipped" within 2 minutes, or "Paid but not shipped" runs.', $order['id']);
    }

    private function orderShipped(Store $store): string
    {
        $order = $store->change(static function (array $data): array {
            $paid = array_values(array_filter($data['orders'], static fn (array $order): bool => 'paid' === $order['status']));
            $order = end($paid) ?: throw new \InvalidArgumentException('No paid order waits to ship. Play "order-paid" first.');
            $data['orders'] = Store::update($data['orders'], 'id', $order['id'], static fn (array $row): array => ['status' => 'shipped'] + $row);

            return [$data, $order];
        });

        $this->webhook('order.shipped', ['order_id' => $order['id'], 'carrier' => 'DHL']);

        return \sprintf('%s shipped, so nothing runs for it.', Store::text($order, 'id'));
    }

    private function badReview(Store $store): string
    {
        $store->change(static function (array $data): array {
            $data['reviews'][] = ['id' => 'rev_'.random_int(400, 999), 'order' => 'PC-1058', 'customer' => 'cus_bruno', 'rating' => 1, 'text' => 'Ordered the Colombia twice and the second bag tasted stale, like it sat on a shelf for months.', 'at' => Store::now(), 'reply' => null];

            return [$data, null];
        });

        return 'Bruno posted a 1-star review. "Review watch" picks it up on its next poll.';
    }

    private function runNow(string $name): string
    {
        $reflex = $this->reflexes->findOneBy(['name' => $name]) ?? throw new \InvalidArgumentException(\sprintf('No reflex "%s". Run reflex:demo:setup first.', $name));
        $run = new Run($reflex);
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $this->bus->dispatch(new RunReflex($run->getId()->toRfc4122()));

        return \sprintf('"%s" runs now.', $name);
    }

    /**
     * What the shop's webhook delivers, taken in as POST /webhook/generic would.
     *
     * @param array<string, mixed> $payload
     */
    private function webhook(string $type, array $payload): void
    {
        $this->intake->receive('generic', 'demo-'.Uuid::v7()->toRfc4122(), $type, $payload);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $data
     */
    private static function nextOrderId(array $data): string
    {
        $numbers = array_map(static fn (array $order): int => (int) substr(Store::text($order, 'id'), 3), $data['orders']);

        return 'PC-'.(max([1000, ...$numbers]) + 1);
    }
}
