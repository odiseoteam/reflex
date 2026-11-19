<?php

declare(strict_types=1);

namespace Demo\PolarCoffee;

use Mcp\Schema\Notification\ResourceListChangedNotification;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Builder;
use Mcp\Server\Exception\ResourceReadException;
use Mcp\Server\Exception\ToolCallException;

/**
 * Polar Coffee's helpdesk: support tickets and their conversations.
 */
final readonly class Support
{
    public function __construct(
        private Store $store,
    ) {
    }

    public function register(Builder $builder): Builder
    {
        $read = new ToolAnnotations(readOnlyHint: true, openWorldHint: false);

        return $builder
            ->setServerInfo('Polar Coffee Support', '1.0.0')
            ->setInstructions('The helpdesk of Polar Coffee. Replies are emailed to the customer.')
            ->addTool($this->listTickets(...), name: 'list_tickets', description: 'Tickets by status: open, pending, solved.', annotations: $read)
            ->addTool($this->getTicket(...), name: 'get_ticket', description: 'A ticket with its whole conversation.', annotations: $read)
            ->addTool($this->findTickets(...), name: 'find_tickets', description: 'All tickets of a customer, by email.', annotations: $read)
            ->addTool($this->replyTicket(...), name: 'reply_ticket', description: 'Emails a reply to the customer on a ticket, optionally changing its status.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true))
            ->addTool($this->tagTicket(...), name: 'tag_ticket', description: 'Adds a tag to a ticket.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))
            ->addTool($this->openTicket(...), name: 'open_ticket', description: 'Opens a ticket for a customer and emails them the first message.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true))
            ->addResourceTemplate($this->ticketResource(...), uriTemplate: 'support://tickets/{id}', name: 'ticket', description: 'A ticket with its conversation.', mimeType: 'application/json');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTickets(string $status = 'open'): array
    {
        $data = $this->store->read();

        return array_values(array_map(
            fn (array $ticket): array => $this->summary($data, $ticket),
            array_filter($data['tickets'], static fn (array $ticket): bool => $ticket['status'] === $status),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function getTicket(string $id): array
    {
        $data = $this->store->read();
        $ticket = Store::find($data['tickets'], 'id', $id) ?? throw new ToolCallException(\sprintf('No ticket "%s".', $id));

        return $this->summary($data, $ticket) + ['messages' => $ticket['messages']];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findTickets(string $email): array
    {
        $data = $this->store->read();
        $customer = Store::find($data['customers'], 'email', mb_strtolower(trim($email))) ?? throw new ToolCallException(\sprintf('No customer with email "%s".', $email));

        return array_values(array_map(
            fn (array $ticket): array => $this->summary($data, $ticket),
            array_filter($data['tickets'], static fn (array $ticket): bool => $ticket['customer'] === $customer['id']),
        ));
    }

    public function replyTicket(string $ticketId, string $message, ?string $status = null): string
    {
        if (null !== $status && !\in_array($status, ['open', 'pending', 'solved'], true)) {
            throw new ToolCallException('The status is open, pending or solved.');
        }

        $result = $this->store->change(static function (array $data) use ($ticketId, $message, $status): array {
            Store::find($data['tickets'], 'id', $ticketId) ?? throw new ToolCallException(\sprintf('No ticket "%s".', $ticketId));
            $data['tickets'] = Store::update($data['tickets'], 'id', $ticketId, static function (array $ticket) use ($message, $status): array {
                $ticket['messages'] = [...Store::rows($ticket, 'messages'), ['from' => 'agent', 'text' => $message, 'at' => Store::now()]];
                $ticket['status'] = $status ?? $ticket['status'];

                return $ticket;
            });

            return [$data, \sprintf('Replied on %s%s.', $ticketId, null === $status ? '' : ' and set it '.$status)];
        });

        // Only the customer's messages notify subscribers, so replying doesn't start another run.
        return $result;
    }

    public function tagTicket(string $ticketId, string $tag): string
    {
        return $this->store->change(static function (array $data) use ($ticketId, $tag): array {
            Store::find($data['tickets'], 'id', $ticketId) ?? throw new ToolCallException(\sprintf('No ticket "%s".', $ticketId));
            $data['tickets'] = Store::update($data['tickets'], 'id', $ticketId, static fn (array $ticket): array => ['tags' => array_values(array_unique([...array_map(strval(...), \is_array($ticket['tags']) ? array_filter($ticket['tags'], \is_string(...)) : []), strtolower($tag)]))] + $ticket);

            return [$data, \sprintf('Tagged %s with "%s".', $ticketId, strtolower($tag))];
        });
    }

    public function openTicket(string $email, string $subject, string $message): string
    {
        $result = $this->store->change(static function (array $data) use ($email, $subject, $message): array {
            $customer = Store::find($data['customers'], 'email', mb_strtolower(trim($email))) ?? throw new ToolCallException(\sprintf('No customer with email "%s".', $email));
            $id = 'T-'.(500 + \count($data['tickets']) + 1);
            $data['tickets'][] = ['id' => $id, 'customer' => $customer['id'], 'subject' => $subject, 'status' => 'pending', 'tags' => [], 'created_at' => Store::now(), 'messages' => [['from' => 'agent', 'text' => $message, 'at' => Store::now()]]];

            return [$data, \sprintf('Opened %s and emailed %s.', $id, Store::text($customer, 'email'))];
        });
        $this->store->publish(new ResourceListChangedNotification());

        return $result;
    }

    public function ticketResource(string $id): string
    {
        try {
            $ticket = $this->getTicket($id);
        } catch (ToolCallException $e) {
            throw new ResourceReadException($e->getMessage());
        }

        return json_encode($ticket, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $data
     * @param array<string, mixed>                      $ticket
     *
     * @return array<string, mixed>
     */
    private function summary(array $data, array $ticket): array
    {
        $customer = Store::find($data['customers'], 'id', $ticket['customer']);
        $messages = Store::rows($ticket, 'messages');
        $last = [] === $messages ? null : $messages[\count($messages) - 1];

        return [
            'id' => $ticket['id'],
            'subject' => $ticket['subject'],
            'status' => $ticket['status'],
            'tags' => $ticket['tags'],
            'customer' => null === $customer ? $ticket['customer'] : ['name' => $customer['name'], 'email' => $customer['email']],
            'created_at' => $ticket['created_at'],
            'last_message' => $last,
        ];
    }
}
