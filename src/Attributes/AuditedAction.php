<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Records each run of an action in the audit log (ADR 0156): who ran it,
 * where, the records it was called on, and the parameters `with:` names.
 *
 *     #[Action(name: 'orders.refund'), Authorize('refund', 'order'), AuditedAction(with: ['reason'])]
 *     public function refund(Order $order, string $reason): ActionResult
 *
 * On an action method, or on its class for every action in it. The entry
 * is written once the body has returned, inside a transaction the runner
 * opens around the action -- so an action that throws, or whose answer is
 * refused, leaves none, and one whose entry cannot be written is taken
 * back. Every action is heard whether or not it carries this: a change an
 * #[Audited] model records names the action it was made in.
 *
 * Only the model parameters are recorded by default, each by its class
 * and key. `with:` names further parameters to record -- a scalar, a
 * backed enum or a model -- by name; one whose name reads as a secret's
 * (a password, a token) is recorded as `[redacted]`, and a form request
 * or an upload is refused, since it holds what nobody chose to keep.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class AuditedAction
{
    /** @param list<string> $with further parameters to record, by name */
    public function __construct(public array $with = []) {}
}
