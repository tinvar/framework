<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Attributes\Notification;
use Tinvar\Auth\Violation;
use Tinvar\Jobs\Queueable;

/**
 * The #[Notification] classes an application has, by type (ADR 0150).
 *
 * Held to what a notification must be when the application starts, rather
 * than at its first send: a class that declares #[Notification] and sends
 * on no channel, a default channel it does not send on, two classes of one
 * type, a constructor that cannot travel in a job's payload. `tinvar check`
 * reports the same refusals (check()).
 */
final class NotificationRegistry
{
    /** The rule a notification the registry refuses breaks, in `tinvar check`. */
    public const string NOTIFICATIONS_MUST_COMPILE = 'notifications-must-compile';

    /** @var array<string, CompiledNotification> */
    private array $byType = [];

    /** @var array<class-string, CompiledNotification> */
    private array $byClass = [];

    /** @param list<class-string> $classes */
    public static function of(array $classes): self
    {
        $registry = new self();
        $registry->registerAll($classes);

        return $registry;
    }

    /** @param class-string $class */
    public function register(string $class): CompiledNotification
    {
        $compiled = self::compile($class);

        $existing = $this->byType[$compiled->type] ?? null;
        if ($existing !== null) {
            throw new \LogicException("Both {$existing->class} and {$class} are the notification '{$compiled->type}'. Give each a type of its own.");
        }
        if (isset($this->byClass[$class])) {
            throw new \LogicException("{$class} is listed as a notification twice.");
        }

        return $this->byType[$compiled->type] = $this->byClass[$class] = $compiled;
    }

    /** @param list<class-string> $classes */
    public function registerAll(array $classes): void
    {
        foreach ($classes as $class) {
            $this->register($class);
        }
    }

    /** @param class-string $class */
    private static function compile(string $class): CompiledNotification
    {
        if (!class_exists($class)) {
            throw new \LogicException("{$class} is listed as a notification and is no class.");
        }
        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(Notification::class);
        if ($attributes === []) {
            throw new \LogicException("{$class} is listed as a notification and declares no #[Notification].");
        }
        if (!$reflection->isInstantiable()) {
            throw new \LogicException("{$class} is a #[Notification] and cannot be made: a notification is a class its sender constructs.");
        }
        $declaration = $attributes[0]->newInstance();

        $channels = NotificationChannel::of($class);
        if ($channels === [] || !is_a($class, Queueable::class, true)) {
            throw new \LogicException(
                "{$class} is a #[Notification] and sends on no channel. Implement MailNotification, PushNotification or "
                . 'InboxNotification -- each with what it sends there.',
            );
        }

        $defaults = $declaration->defaults ?? $channels;
        foreach ($defaults as $channel) {
            if (!\in_array($channel, $channels, true)) {
                throw new \LogicException(
                    "{$class} lists {$channel->value} among its defaults and does not send on it. Implement "
                    . (new \ReflectionClass($channel->interface()))->getShortName() . ', or take it off the defaults.',
                );
            }
        }

        // Every send is queued, and travels as its constructor's arguments
        // read back from its properties (Jobs\Payload): one that cannot is
        // refused here, not on the first send.
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isVariadic() || !$reflection->hasProperty($parameter->getName())) {
                throw new \LogicException(
                    "{$class}::__construct(\${$parameter->getName()}) cannot travel in a job: every parameter of a notification "
                    . 'is a property of the same name, as a promoted one is.',
                );
            }
        }

        return new CompiledNotification(
            class: $class,
            type: $declaration->type,
            label: $declaration->label,
            description: $declaration->description,
            channels: $channels,
            defaults: array_values(array_filter($channels, static fn (NotificationChannel $c): bool => \in_array($c, $defaults, true))),
        );
    }

    /**
     * What `tinvar check` reports for an application's notifications: each
     * class the registry refuses.
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
                $violations[] = new Violation(self::NOTIFICATIONS_MUST_COMPILE, $class, $e->getMessage());
            }
        }

        return $violations;
    }

    public function forClass(string $class): ?CompiledNotification
    {
        return $this->byClass[$class] ?? null;
    }

    public function forType(string $type): ?CompiledNotification
    {
        return $this->byType[$type] ?? null;
    }

    /** @return list<CompiledNotification> by type */
    public function all(): array
    {
        $all = $this->byType;
        ksort($all);

        return array_values($all);
    }

    /** Whether any registered notification sends on $channel. */
    public function anySends(NotificationChannel $channel): ?CompiledNotification
    {
        foreach ($this->all() as $notification) {
            if ($notification->sends($channel)) {
                return $notification;
            }
        }

        return null;
    }
}
