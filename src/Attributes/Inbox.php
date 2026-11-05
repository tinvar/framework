<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Where a signed-in user reads their notifications (ADR 0155): the entry
 * point of their in-app inbox, by name.
 *
 *     #[Inbox('customer'), Middleware(RequireSignIn::class)]
 *     final readonly class CustomerInbox {}
 *
 * The class says nothing but its declarations, which are the inbox's
 * entry point's -- its #[TenantFrom], #[Panel], #[Domain] and #[Middleware]
 * -- and run around every call to it, behind CSRF, the `inbox` budget and
 * authentication with no opt-out: POST /_inbox/{name} for the feed,
 * /_inbox/{name}/read to mark entries read, and /_inbox/{name}/preferences
 * for which channels the user wants for each kind of notification. Its
 * live updates are the personal channel `inbox.{name}`, let in through the
 * same declarations.
 *
 * Every inbox reads the one feed a user has in a tenant: an application
 * on two hosts, or with a panel of its own, declares one for each.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Inbox
{
    public const string NAME = '/^[a-z][a-z0-9_]{0,31}\z/';

    public function __construct(public string $name = 'inbox')
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new \InvalidArgumentException("'{$name}' is not an inbox name: a lowercase identifier of up to 32 characters, as in 'customer'.");
        }
    }
}
