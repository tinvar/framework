<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Offers an action or a query to a model as a tool.
 *
 *     #[Query(name: 'orders.shipments'), Authorize('view', 'order'),
 *         Tool('Where an order is on its way, newest step first.', name: 'order_shipments')]
 *     final class ShippingUpdates { ... }
 *
 * On an #[Action] method, or a #[Query] class. Nothing about the operation
 * changes: a tool call runs it through the same resolution, #[Authorize]
 * and validation as a user's call, as the user the conversation is for, in
 * their tenant and panel (Tinvar\Tools\ToolCalls). The description is what
 * the model reads to decide when to call it, so it says what the tool does
 * and returns, not how. The name is the tool's name to the model: letters,
 * digits, '_' and '-', up to 64; the operation's name with its dots made
 * underscores unless given.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Tool
{
    public function __construct(
        public string $description,
        public ?string $name = null,
    ) {
        if (trim($description) === '') {
            throw new \InvalidArgumentException('A tool needs a description: it is how a model knows when to call it.');
        }
        if ($name !== null && preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $name) !== 1) {
            throw new \InvalidArgumentException("'{$name}' is not a tool name: letters, digits, '_' and '-', up to 64.");
        }
    }

    /** The tool's name for an operation named $operation. */
    public function nameFor(string $operation): string
    {
        $name = $this->name ?? preg_replace('/[^A-Za-z0-9_-]/', '_', $operation) ?? $operation;
        if (preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $name) !== 1) {
            throw new \InvalidArgumentException("The operation '{$operation}' makes no tool name; give #[Tool] a name.");
        }

        return $name;
    }
}
