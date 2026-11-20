<?php

declare(strict_types=1);

namespace Demo\PolarCoffee;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\EnumSchemaDefinition;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Builder;
use Mcp\Server\Exception\ResourceReadException;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/**
 * Polar Coffee Store's online shop: customers, orders, payments, refunds, coupons and reviews.
 */
final readonly class Shop
{
    private const float MAX_COUPON_PERCENT = 30.0;

    public function __construct(
        private Store $store,
    ) {
    }

    public function register(Builder $builder): Builder
    {
        $read = new ToolAnnotations(readOnlyHint: true, openWorldHint: false);

        return $builder
            ->setServerInfo('Polar Coffee Store Shop', '1.0.0')
            ->setInstructions('The online shop of Polar Coffee Store, a specialty coffee store. Amounts are in EUR.')
            ->addTool($this->findCustomer(...), name: 'find_customer', description: 'A customer by email, with their orders, refunds, coupons and lifetime value.', annotations: $read)
            ->addTool($this->getOrder(...), name: 'get_order', description: 'An order with its items, payments and refunds.', annotations: $read)
            ->addTool($this->listOrders(...), name: 'list_orders', description: 'Latest orders, optionally by status: pending, paid, payment_failed, shipped, refunded.', annotations: $read)
            ->addTool($this->listReviews(...), name: 'list_reviews', description: 'Product reviews, newest first.', annotations: $read)
            ->addTool($this->retryPayment(...), name: 'retry_payment', description: 'Charges the card of a failed order again.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true))
            ->addTool($this->issueCoupon(...), name: 'issue_coupon', description: 'Emails a one-time discount code to a customer, up to 30%.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true))
            ->addTool($this->refundOrder(...), name: 'refund_order', description: 'Refunds part or all of an order. Asks whether to refund the card or give store credit.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true))
            ->addTool($this->replyReview(...), name: 'reply_review', description: 'Publishes the shop\'s public reply under a review.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true))
            ->addResourceTemplate($this->orderResource(...), uriTemplate: 'shop://orders/{id}', name: 'order', description: 'An order with its payments and refunds.', mimeType: 'application/json');
    }

    /**
     * @return array<string, mixed>
     */
    public function findCustomer(string $email): array
    {
        $data = $this->store->read();
        $customer = Store::find($data['customers'], 'email', mb_strtolower(trim($email)))
            ?? throw new ToolCallException(\sprintf('No customer with email "%s".', $email));

        $orders = array_values(array_filter($data['orders'], static fn (array $order): bool => $order['customer'] === $customer['id']));
        $paid = array_filter($orders, static fn (array $order): bool => \in_array($order['status'], ['paid', 'shipped'], true));

        return $customer + [
            'orders' => array_map(static fn (array $order): array => ['id' => $order['id'], 'total' => $order['total'], 'status' => $order['status'], 'created_at' => $order['created_at']], $orders),
            'lifetime_value' => array_sum(array_column($paid, 'total')),
            'refunds' => array_values(array_filter($data['refunds'], static fn (array $refund): bool => \in_array($refund['order'], array_column($orders, 'id'), true))),
            'coupons' => array_values(array_filter($data['coupons'], static fn (array $coupon): bool => $coupon['customer'] === $customer['id'])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(string $id): array
    {
        return $this->order($this->store->read(), $id) ?? throw new ToolCallException(\sprintf('No order "%s".', $id));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOrders(?string $status = null, int $limit = 10): array
    {
        $orders = array_values(array_filter($this->store->read()['orders'], static fn (array $order): bool => null === $status || $order['status'] === $status));
        usort($orders, static fn (array $a, array $b): int => strcmp(Store::text($b, 'created_at'), Store::text($a, 'created_at')));

        return \array_slice($orders, 0, max(1, min(50, $limit)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listReviews(int $limit = 10): array
    {
        $reviews = $this->store->read()['reviews'];
        usort($reviews, static fn (array $a, array $b): int => strcmp(Store::text($b, 'at'), Store::text($a, 'at')));

        return \array_slice($reviews, 0, max(1, min(50, $limit)));
    }

    public function retryPayment(string $orderId): string
    {
        return $this->store->change(static function (array $data) use ($orderId): array {
            $order = Store::find($data['orders'], 'id', $orderId) ?? throw new ToolCallException(\sprintf('No order "%s".', $orderId));
            if ('payment_failed' !== $order['status']) {
                throw new ToolCallException(\sprintf('Order %s is %s; only failed payments can be retried.', $orderId, Store::text($order, 'status')));
            }

            // The card on file keeps declining; a retry only works once the customer updated it.
            $customer = Store::find($data['customers'], 'id', $order['customer']);
            $succeeds = true === ($customer['card_updated'] ?? false);
            $data['payments'][] = ['id' => 'pay_'.random_int(9000, 9999), 'order' => $orderId, 'amount' => $order['total'], 'status' => $succeeds ? 'succeeded' : 'failed', 'failure' => $succeeds ? null : 'card_declined: insufficient_funds', 'at' => Store::now()];
            if ($succeeds) {
                $data['orders'] = Store::update($data['orders'], 'id', $orderId, static fn (array $row): array => ['status' => 'paid'] + $row);
            }

            return [$data, $succeeds ? \sprintf('Charged %.2f EUR for %s; the order is paid.', Store::amount($order, 'total'), $orderId) : 'Declined again: insufficient funds. Retrying will not help until the customer updates their card.'];
        });
    }

    public function issueCoupon(string $email, float $percent, string $reason): string
    {
        if ($percent <= 0 || $percent > self::MAX_COUPON_PERCENT) {
            throw new ToolCallException(\sprintf('A coupon is between 1 and %d%%.', self::MAX_COUPON_PERCENT));
        }

        return $this->store->change(static function (array $data) use ($email, $percent, $reason): array {
            $customer = Store::find($data['customers'], 'email', mb_strtolower(trim($email))) ?? throw new ToolCallException(\sprintf('No customer with email "%s".', $email));
            $code = 'POLAR-'.strtoupper(bin2hex(random_bytes(3)));
            $data['coupons'][] = ['code' => $code, 'customer' => $customer['id'], 'percent' => $percent, 'reason' => $reason, 'at' => Store::now()];

            return [$data, \sprintf('Emailed coupon %s (%s%% off) to %s.', $code, $percent, Store::text($customer, 'email'))];
        });
    }

    public function refundOrder(string $orderId, float $amount, string $reason, RequestContext $context): CallToolResult
    {
        $order = $this->getOrder($orderId);
        $refunded = array_sum(array_map(static fn (array $refund): float => Store::amount($refund, 'amount'), Store::rows($order, 'refunds')));
        $refundable = Store::amount($order, 'total') - $refunded;
        if ($amount <= 0 || $amount > $refundable) {
            throw new ToolCallException(\sprintf('Order %s can be refunded up to %.2f EUR.', $orderId, $refundable));
        }
        $customer = \is_array($order['customer']) ? $order['customer'] : [];

        $answer = $context->getClientGateway()->elicit(
            \sprintf('Refund %.2f EUR of order %s to the card, or as store credit worth 10%% more?', $amount, $orderId),
            new ElicitationSchema(['method' => new EnumSchemaDefinition('Refund as', ['card', 'store_credit'])], ['method']),
        );
        if (!$answer->isAccepted()) {
            return new CallToolResult([new TextContent('Not refunded: the person '.$answer->action->value.'d.')], true);
        }
        $method = 'store_credit' === ($answer->content['method'] ?? null) ? 'store_credit' : 'card';

        $message = $this->store->change(static function (array $data) use ($orderId, $customer, $amount, $reason, $method, $refundable): array {
            $credit = round($amount * 1.1, 2);
            $data['refunds'][] = ['id' => 're_'.random_int(1000, 9999), 'order' => $orderId, 'amount' => $amount, 'method' => $method, 'reason' => $reason, 'at' => Store::now()];
            if ('store_credit' === $method) {
                $data['customers'] = Store::update($data['customers'], 'id', $customer['id'] ?? null, static fn (array $row): array => ['store_credit' => Store::amount($row, 'store_credit') + $credit] + $row);
            }
            if ($amount >= $refundable) {
                $data['orders'] = Store::update($data['orders'], 'id', $orderId, static fn (array $row): array => ['status' => 'refunded'] + $row);
            }

            return [$data, 'store_credit' === $method
                ? \sprintf('Gave %.2f EUR of store credit to %s for order %s.', $credit, Store::text($customer, 'email'), $orderId)
                : \sprintf('Refunded %.2f EUR of order %s to the card.', $amount, $orderId)];
        });
        $this->store->resourceChanged('shop://orders/'.$orderId);

        return new CallToolResult([new TextContent($message)]);
    }

    public function replyReview(string $reviewId, string $reply): string
    {
        return $this->store->change(static function (array $data) use ($reviewId, $reply): array {
            Store::find($data['reviews'], 'id', $reviewId) ?? throw new ToolCallException(\sprintf('No review "%s".', $reviewId));
            $data['reviews'] = Store::update($data['reviews'], 'id', $reviewId, static fn (array $row): array => ['reply' => $reply] + $row);

            return [$data, \sprintf('Replied publicly to review %s.', $reviewId)];
        });
    }

    public function orderResource(string $id): string
    {
        $order = $this->order($this->store->read(), $id) ?? throw new ResourceReadException(\sprintf('No order "%s".', $id));

        return json_encode($order, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $data
     *
     * @return array<string, mixed>|null
     */
    private function order(array $data, string $id): ?array
    {
        $order = Store::find($data['orders'], 'id', $id);
        if (null === $order) {
            return null;
        }

        $customer = Store::find($data['customers'], 'id', $order['customer']);
        $order['customer'] = null === $customer ? $order['customer'] : ['id' => $customer['id'], 'name' => $customer['name'], 'email' => $customer['email']];
        $order['payments'] = array_values(array_filter($data['payments'], static fn (array $payment): bool => $payment['order'] === $id));
        $order['refunds'] = array_values(array_filter($data['refunds'], static fn (array $refund): bool => $refund['order'] === $id));

        return $order;
    }
}
