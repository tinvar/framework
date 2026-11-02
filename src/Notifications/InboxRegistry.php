<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Attributes\Domain;
use Tinvar\Attributes\Inbox;
use Tinvar\Attributes\Middleware;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Attributes\WithoutSession;
use Tinvar\Auth\PanelBinding;
use Tinvar\Auth\Violation;

/**
 * The #[Inbox] classes an application declares, by name (ADR 0152): each
 * the entry point a signed-in user reads their notifications through.
 *
 * Refused here, when the application starts: an inbox anyone may open
 * (#[PublicAccess]) -- an inbox is a user's own -- one with no session,
 * two of one name, and declarations that do not hold together.
 */
final class InboxRegistry
{
    public const string INBOXES_MUST_COMPILE = 'inboxes-must-compile';

    /** @var array<string, CompiledInbox> */
    private array $byName = [];

    /** @param list<class-string> $classes */
    public static function of(array $classes): self
    {
        $registry = new self();
        foreach ($classes as $class) {
            $registry->register($class);
        }

        return $registry;
    }

    /** @param class-string $class */
    public function register(string $class): CompiledInbox
    {
        if (!class_exists($class)) {
            throw new \LogicException("{$class} is listed as an inbox and is no class.");
        }
        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(Inbox::class);
        if ($attributes === []) {
            throw new \LogicException("{$class} is listed as an inbox and declares no #[Inbox].");
        }
        $name = $attributes[0]->newInstance()->name;
        if (PublicAccess::declaredOn($class)) {
            throw new \LogicException("{$class} is an #[Inbox] and #[PublicAccess]. An inbox is a signed-in user's own: take #[PublicAccess] off.");
        }
        if ($reflection->getAttributes(WithoutSession::class) !== []) {
            throw new \LogicException("{$class} is an #[Inbox] and #[WithoutSession]. An inbox is read by a session's user, so it has one.");
        }

        try {
            $panels = Panel::declaredOn($class);
            PanelBinding::of($panels);
            $compiled = new CompiledInbox(
                name: $name,
                class: $class,
                tenantFrom: TenantFrom::declaredOn($class),
                panels: $panels,
                middleware: Middleware::declaredOn($class),
                host: Domain::declaredOn($class)?->pattern,
            );
        } catch (\InvalidArgumentException $e) {
            throw new \LogicException("{$class}: {$e->getMessage()}", 0, $e);
        }

        $existing = $this->byName[$name] ?? null;
        if ($existing !== null) {
            throw new \LogicException("Both {$existing->class} and {$class} are the inbox '{$name}'. Give each a name of its own.");
        }

        return $this->byName[$name] = $compiled;
    }

    /**
     * What `tinvar check` reports for an application's inboxes.
     *
     * @param list<class-string> $classes
     * @return list<Violation>
     */
    public static function check(array $classes): array
    {
        $registry = new self();
        $violations = [];
        foreach ($classes as $class) {
            try {
                $registry->register($class);
            } catch (\LogicException|\InvalidArgumentException $e) {
                $violations[] = new Violation(self::INBOXES_MUST_COMPILE, $class, $e->getMessage());
            }
        }

        return $violations;
    }

    public function get(string $name): ?CompiledInbox
    {
        return $this->byName[$name] ?? null;
    }

    /** @return list<CompiledInbox> by name */
    public function all(): array
    {
        $all = $this->byName;
        ksort($all);

        return array_values($all);
    }

    /** @return list<string> the personal channel of each, by name */
    public function channels(): array
    {
        return array_map(static fn (CompiledInbox $inbox): string => $inbox->channel(), $this->all());
    }
}
