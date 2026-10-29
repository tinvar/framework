<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Lets the named services call an action or a query with a service context
 * token (Tinvar\Services\ServiceCalls).
 *
 *     #[Action(name: 'orders.collected'), Authorize('collect', 'order'), ServiceCall('warehouse')]
 *     public function collected(Order $order): ActionResult { ... }
 *
 * On an #[Action] method, or a #[Query] class. Nothing about the operation
 * changes: a service call runs it through the same resolution, #[Authorize]
 * and validation as a user's call, with the service signed in as the user,
 * in the tenant and panel its token was issued for. A service not named
 * here cannot call it, whatever token it holds.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class ServiceCall
{
    /** @var list<string> */
    public array $services;

    public function __construct(string ...$services)
    {
        if ($services === []) {
            throw new \InvalidArgumentException('#[ServiceCall] names the services that may call it.');
        }
        foreach ($services as $service) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $service) !== 1) {
                throw new \InvalidArgumentException("'{$service}' is not a service name: letters, digits, '.', '_' and '-', up to 64.");
            }
        }
        $this->services = array_values($services);
    }

    public function allows(string $service): bool
    {
        return \in_array($service, $this->services, true);
    }
}
