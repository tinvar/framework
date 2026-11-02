<?php

declare(strict_types=1);

namespace Tinvar\Channels;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Channel;
use Tinvar\Attributes\Middleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Attributes\WithoutSession;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\PanelBinding;
use Tinvar\Auth\Violation;
use Tinvar\Data\Data;
use Tinvar\Orm\Model;
use Tinvar\Support\KnownAttributes;
use Tinvar\Support\Str;

/**
 * The #[Channel] classes an application lists, by name.
 *
 * Compilation is where a channel is held to the build rules, as an
 * endpoint is: a model parameter no #[Authorize] names, a channel that says
 * nothing of who may subscribe, a pattern parameter the constructor does
 * not take, a message that is not a Data class -- each refused here, when
 * the application starts, rather than on the first subscription. `tinvar
 * check` reports the same refusals (check()).
 */
final class ChannelRegistry
{
    /** The rule a channel the registry refuses breaks, in `tinvar check`. */
    public const string CHANNELS_MUST_COMPILE = 'channels-must-compile';

    /** A pattern's identifiers, and a parameter's name. */
    private const string LITERAL = '/^[A-Za-z0-9_-]{1,64}\z/';

    private const string PARAMETER = '/^\{([A-Za-z_][A-Za-z0-9_]{0,63})\}\z/';

    /** Long enough for any name a person would write; short enough to hash and log. */
    public const int MAX_PATTERN_BYTES = 200;

    /** @var array<string, CompiledChannel> */
    private array $byName = [];

    /** @var array<class-string, CompiledChannel> */
    private array $byClass = [];

    /**
     * @param list<class-string<\Tinvar\Orm\Model>> $models the application's models, which
     *        tenant-scoped-ids-must-be-typed reads as each class registers (ADR 0105)
     */
    public function __construct(private readonly array $models = []) {}

    /** @param class-string $class */
    public function register(string $class): CompiledChannel
    {
        if (!class_exists($class)) {
            throw new ChannelException("{$class} is listed as a channel and is no class.");
        }
        $reflection = new \ReflectionClass($class);
        try {
            KnownAttributes::check($reflection);
        } catch (\LogicException $e) {
            throw new ChannelException($e->getMessage(), 0, $e);
        }

        // A subscription parameter that is a bare id naming a tenant's model
        // is looked up unscoped (ADR 0105).
        $bare = BuildRules::bareTenantIds($class, $this->models);
        if ($bare !== null) {
            throw new ChannelException($bare);
        }

        $compiled = $this->compile($reflection);

        $existing = $this->byName[$compiled->name] ?? null;
        if ($existing !== null) {
            throw new ChannelException("Both {$existing->class} and {$compiled->class} are the channel '{$compiled->name}'.");
        }
        if (isset($this->byClass[$compiled->class])) {
            throw new ChannelException("{$compiled->class} is listed as a channel twice.");
        }

        $this->byName[$compiled->name] = $compiled;
        $this->byClass[$compiled->class] = $compiled;

        return $compiled;
    }

    /**
     * A personal channel: one each signed-in user hears for themselves
     * alone, published to for one user by name (Channels::publishTo()).
     * For a module that declares its own entry point -- a notification
     * inbox -- rather than a #[Channel] class, and let in as any channel
     * is, through the stack $class declares: its #[TenantFrom], #[Panel],
     * #[Domain] and #[Middleware], and authentication with no opt-out. It
     * takes no parameters, and needs no #[Authorize]: its topic is worked
     * out from the signed-in user's id (ChannelTopics::forChannel()), so
     * there is nobody else's to ask for.
     *
     * @param class-string $class the class whose declarations are its entry point's
     * @param class-string<Data> $message the Data class every message is
     * @throws ChannelException for a name taken or malformed, a message that is no Data
     *         class, or a class that is #[PublicAccess] or #[WithoutSession]
     */
    public function registerPersonal(string $class, string $name, string $message): CompiledChannel
    {
        if (!class_exists($class)) {
            throw new ChannelException("{$class} is named as a personal channel's entry point and is no class.");
        }
        if (!Str::isDottedIdentifier($name)) {
            throw new ChannelException("{$class}: '{$name}' is not a channel name: dot-separated identifiers, as in inbox.customers.");
        }
        if (!class_exists($message) || !is_subclass_of($message, Data::class)) {
            throw new ChannelException("{$class}: a personal channel carries one Data class, and '{$message}' is none.");
        }
        $reflection = new \ReflectionClass($class);
        if (PublicAccess::declaredOn($class)) {
            throw new ChannelException(
                "{$class} is #[PublicAccess], and its channel '{$name}' is a signed-in user's own. Take #[PublicAccess] off: "
                . 'a visitor has no user to hear it as.',
            );
        }
        if ($reflection->getAttributes(WithoutSession::class) !== []) {
            throw new ChannelException("{$class} is #[WithoutSession]. A subscription is bound to a session, so its channel has one.");
        }

        try {
            $tenantFrom = TenantFrom::declaredOn($class);
            $panels = Panel::declaredOn($class);
            PanelBinding::of($panels);
            $host = \Tinvar\Attributes\Domain::declaredOn($class)?->pattern;
        } catch (\LogicException|\InvalidArgumentException $e) {
            throw new ChannelException("{$class}: {$e->getMessage()}", 0, $e);
        }

        $compiled = new CompiledChannel(
            name: $name,
            class: $class,
            pattern: $name,
            parameters: [],
            models: [],
            scalars: [],
            checks: [],
            exempt: true,
            middleware: Middleware::declaredOn($class),
            tenantFrom: $tenantFrom,
            panels: $panels,
            publicAccess: false,
            message: $message,
            host: $host,
            personal: true,
        );

        $existing = $this->byName[$name] ?? null;
        if ($existing !== null) {
            throw new ChannelException("Both {$existing->class} and {$class} are the channel '{$name}'.");
        }
        $this->byName[$name] = $compiled;
        // Found by its class too, when it is no #[Channel] of its own, so
        // Channels::publish() refuses it by name rather than as unknown.
        $this->byClass[$class] ??= $compiled;

        return $compiled;
    }

    /**
     * @param list<class-string> $classes
     * @return list<CompiledChannel>
     */
    public function registerAll(array $classes): array
    {
        return array_map($this->register(...), $classes);
    }

    /** @param \ReflectionClass<object> $reflection */
    private function compile(\ReflectionClass $reflection): CompiledChannel
    {
        $class = $reflection->getName();
        $attributes = $reflection->getAttributes(Channel::class);
        if ($attributes === []) {
            throw new ChannelException("{$class} is listed as a channel and declares no #[Channel].");
        }
        if (!$reflection->isInstantiable()) {
            throw new ChannelException("{$class} is a #[Channel] and cannot be made: a channel is a class its publisher constructs.");
        }

        $declaration = $attributes[0]->newInstance();

        if (!class_exists($declaration->message) || !is_subclass_of($declaration->message, Data::class)) {
            throw new ChannelException(
                "{$class}: its message is '{$declaration->message}', which is no Data class. A channel carries one Data "
                . 'class, through the encoder, which refuses anything else.',
            );
        }

        [$parameters, $literals] = self::compilePattern($class, $declaration->pattern);

        $name = $declaration->name ?? implode('.', $literals);
        if (!Str::isDottedIdentifier($name)) {
            throw new ChannelException(
                "{$class}: '{$name}' is not a channel name: dot-separated identifiers, as in orders.updates. A pattern "
                . 'with no identifier of its own needs a name: #[Channel(..., name: \'...\')].',
            );
        }

        if (WithoutSession::declaredOn($class, '__construct') || $reflection->getAttributes(WithoutSession::class) !== []) {
            throw new ChannelException(
                "{$class} is a #[Channel] and #[WithoutSession]. A subscription is bound to a session, so a channel has one.",
            );
        }

        $exemption = $reflection->getAttributes(NoAuthorization::class)[0] ?? null;
        $exempt = $exemption !== null;
        if ($exemption !== null && trim($exemption->newInstance()->reason) === '') {
            throw new ChannelException("{$class} declares #[NoAuthorization] with no reason. Say why anyone may subscribe.");
        }

        $checks = Authorize::declaredOn($class, '__construct');
        if ($checks === [] && !$exempt) {
            // As a query must: a channel with no model to name would
            // otherwise say nothing of who may subscribe, and so let anyone.
            throw new ChannelException(
                "{$class} is a #[Channel] and declares no #[Authorize]. Say who may subscribe -- as in "
                . "#[Authorize('view', 'order')] -- or #[NoAuthorization(reason: '...')].",
            );
        }

        $models = [];
        $scalars = [];
        $taken = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $parameterName = $parameter->getName();
            $where = "{$class}::__construct(\${$parameterName})";
            $type = $parameter->getType();
            if ($parameter->isVariadic() || !$type instanceof \ReflectionNamedType) {
                throw new ChannelException("{$where} cannot be bound; give it one type.");
            }
            if (!\in_array($parameterName, $parameters, true)) {
                throw new ChannelException(
                    "{$where} is not in the pattern '{$declaration->pattern}'. A channel's constructor takes its "
                    . 'pattern\'s parameters and nothing else: it is the channel\'s address.',
                );
            }
            if (!$parameter->isPromoted()) {
                throw new ChannelException(
                    "{$where} is not a promoted property. A channel is read back from its properties when PHP publishes to it.",
                );
            }
            if ($type->allowsNull() || $parameter->isDefaultValueAvailable()) {
                throw new ChannelException("{$where} may be left out. Every parameter of a channel names part of its address.");
            }
            $taken[] = $parameterName;

            $typeName = $type->getName();
            if (!$type->isBuiltin() && is_subclass_of($typeName, Model::class)) {
                $models[$parameterName] = $typeName;
                continue;
            }
            if ($type->isBuiltin() ? \in_array($typeName, ['string', 'int', 'bool'], true) : is_subclass_of($typeName, \BackedEnum::class)) {
                $scalars[$parameterName] = $typeName;
                continue;
            }

            throw new ChannelException("{$where} is typed {$typeName}. A channel's parameter is a model, a string, int or bool, or a backed enum.");
        }

        foreach ($parameters as $parameterName) {
            if (!\in_array($parameterName, $taken, true)) {
                throw new ChannelException(
                    "{$class}: its pattern has {{$parameterName}}, and its constructor takes no \${$parameterName}. "
                    . 'Every part of the pattern is an input, checked and bound.',
                );
            }
        }

        $covered = [];
        foreach ($checks as $check) {
            $argument = $check->argument;
            if ($argument === null) {
                throw new ChannelException(
                    "{$class} declares #[Authorize('{$check->ability}')] with no subject. Name the model it covers, or "
                    . "a class, as in Authorize('viewAny', Order::class).",
                );
            }
            if (isset($models[$argument])) {
                $covered[] = $argument;
                continue;
            }
            if (isset($scalars[$argument])) {
                throw new ChannelException(
                    "{$class}: #[Authorize('{$check->ability}', '{$argument}')] names a parameter that is not a model. A "
                    . 'policy would be handed a raw value; type it as the model so it is resolved and scoped.',
                );
            }
            if (!class_exists($argument) && !interface_exists($argument)) {
                throw new ChannelException(
                    "{$class} declares #[Authorize('{$check->ability}', '{$argument}')], but '{$argument}' is neither a "
                    . 'model parameter of the channel nor a class.',
                );
            }
        }

        if (!$exempt) {
            foreach ($models as $parameterName => $model) {
                if (!\in_array($parameterName, $covered, true)) {
                    throw new ChannelException(
                        "{$class}::__construct(\${$parameterName}) takes {$model} but no #[Authorize] names it. Add "
                        . "#[Authorize('<ability>', '{$parameterName}')], or #[NoAuthorization(reason: '...')] if it "
                        . 'genuinely needs none.',
                    );
                }
            }
        }

        try {
            $tenantFrom = TenantFrom::declaredOn($class);
            $panels = Panel::declaredOn($class);
            PanelBinding::of($panels);
            $host = \Tinvar\Attributes\Domain::declaredOn($class)?->pattern;
        } catch (\LogicException|\InvalidArgumentException $e) {
            throw new ChannelException("{$class}: {$e->getMessage()}", 0, $e);
        }

        return new CompiledChannel(
            name: $name,
            class: $class,
            pattern: $declaration->pattern,
            parameters: $parameters,
            models: $models,
            scalars: $scalars,
            checks: $checks,
            exempt: $exempt,
            // Read where its checks are: the class, then its constructor. A
            // #[Middleware] -- or a package's #[RequiresPlan] (ADR 0150) --
            // written on the constructor beside an #[Authorize] was dropped.
            middleware: Middleware::declaredOn($class, '__construct'),
            tenantFrom: $tenantFrom,
            panels: $panels,
            publicAccess: PublicAccess::declaredOn($class),
            message: $declaration->message,
            host: $host,
        );
    }

    /**
     * A pattern's parameters and its identifiers, or a refusal.
     *
     * @return array{list<string>, list<string>}
     */
    private static function compilePattern(string $class, string $pattern): array
    {
        if ($pattern === '' || \strlen($pattern) > self::MAX_PATTERN_BYTES) {
            throw new ChannelException("{$class}: a channel's pattern is 1 to " . self::MAX_PATTERN_BYTES . ' bytes.');
        }

        $parameters = [];
        $literals = [];
        foreach (explode('.', $pattern) as $segment) {
            if (preg_match(self::PARAMETER, $segment, $match) === 1) {
                if (\in_array($match[1], $parameters, true)) {
                    throw new ChannelException("{$class}: '{$pattern}' names {{$match[1]}} twice.");
                }
                $parameters[] = $match[1];
                continue;
            }
            if (preg_match(self::LITERAL, $segment) !== 1) {
                throw new ChannelException(
                    "{$class}: '{$pattern}' has '{$segment}', which is neither an identifier nor a {parameter}. "
                    . 'A pattern is dot-separated, as in orders.{order}.',
                );
            }
            $literals[] = $segment;
        }

        return [$parameters, $literals];
    }

    /**
     * What `tinvar check` reports for an application's channels: each
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
            } catch (\RuntimeException|\LogicException $e) {
                $violations[] = new Violation(self::CHANNELS_MUST_COMPILE, $class, $e->getMessage());
            }
        }

        return $violations;
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    public function get(string $name): CompiledChannel
    {
        return $this->byName[$name] ?? throw new ChannelException("No channel is named '{$name}'.");
    }

    /** The channel a class declares, registered; null for a class that is none. */
    public function forClass(string $class): ?CompiledChannel
    {
        return $this->byClass[$class] ?? null;
    }

    /** @return list<CompiledChannel> by name */
    public function all(): array
    {
        $all = $this->byName;
        ksort($all);

        return array_values($all);
    }
}
