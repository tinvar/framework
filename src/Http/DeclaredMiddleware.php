<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Attributes\ClassAttributes;

/**
 * The middleware a MiddlewareAttribute puts around an entry point, as it
 * stands in the entry point's middleware list beside #[Middleware]'s
 * classes (ADR 0150).
 *
 * A list of middleware is a list of class names, compiled once when an
 * entry point registers and built by the resolver with each request. An
 * attribute's middleware needs the attribute too, so it is listed by a
 * reference to where the attribute is written:
 *
 *     Acme\Billing\RequiresPlan@App\Http\Pages\Reports#0                 // on the class, its ancestors or traits
 *     Acme\Billing\RequiresPlan@App\Http\Actions\Exports::download#0     // on the method
 *
 * the attribute's class, then the entry point's class (and method), then
 * which of its middleware attributes it is, counted as on() counts them. A
 * class name holds neither `@` nor `#`, so a reference is never taken for
 * one. build() reads the attribute back by reflection and checks it is the
 * class the reference names: a reference that no longer points at it --
 * code changed under a cached list -- fails, never runs another check.
 */
final class DeclaredMiddleware
{
    /** @var array<string, MiddlewareAttribute> reference => attribute, read once a worker */
    private static array $attributes = [];

    /**
     * The references for every middleware attribute on $class -- its
     * ancestors' and traits' first, as ClassAttributes reads them -- or, given
     * $method, on that method alone.
     *
     * @param class-string|\ReflectionClass<object> $class
     * @return list<string>
     */
    public static function on(string|\ReflectionClass $class, ?string $method = null): array
    {
        $reflection = $class instanceof \ReflectionClass ? $class : new \ReflectionClass($class);
        $where = $reflection->getName() . ($method === null ? '' : "::{$method}");

        $references = [];
        foreach (self::attributesAt($reflection, $method) as $index => $attribute) {
            $references[] = "{$attribute->getName()}@{$where}#{$index}";
        }

        return $references;
    }

    /** Whether $name is a reference on() wrote, rather than a middleware class. */
    public static function isReference(string $name): bool
    {
        return str_contains($name, '@') && str_contains($name, '#');
    }

    /**
     * The attribute a reference points at.
     *
     * @throws \LogicException for a reference that points at nothing, or at another attribute
     */
    public static function attribute(string $reference): MiddlewareAttribute
    {
        if (isset(self::$attributes[$reference])) {
            return self::$attributes[$reference];
        }

        if (preg_match('/^([^@#]+)@([^@#:]+)(?:::([A-Za-z_][A-Za-z0-9_]*))?#(\d+)\z/', $reference, $m) !== 1) {
            throw new \LogicException("'{$reference}' is not a reference to a middleware attribute.");
        }
        [, $attributeClass, $class, $method, $index] = $m;
        if (!class_exists($class)) {
            throw new \LogicException("'{$reference}' names {$class}, which is no class.");
        }
        $reflection = new \ReflectionClass($class);
        if ($method !== '' && !$reflection->hasMethod($method)) {
            throw new \LogicException("'{$reference}' names {$class}::{$method}(), which is no method.");
        }

        $found = self::attributesAt($reflection, $method === '' ? null : $method)[(int) $index] ?? null;
        if ($found === null || strcasecmp($found->getName(), $attributeClass) !== 0) {
            throw new \LogicException("'{$reference}' points at no #[{$attributeClass}] there now: rebuild what compiled it.");
        }
        return self::$attributes[$reference] = $found->newInstance();
    }

    /**
     * The middleware a reference stands for: the attribute's middleware(),
     * built by $resolver, handed the attribute with each request -- and
     * deferred: where it stands in the stack it only records itself, and
     * the entry point's runner runs it once its #[Authorize] checks have
     * passed (EntryGates).
     *
     * @param \Closure(class-string): object $resolver
     * @throws \LogicException for a reference that points at nothing, or a middleware that cannot be built
     */
    public static function build(string $reference, \Closure $resolver): Middleware
    {
        [$attribute, $handler] = self::handler($reference, $resolver);

        // Deferred where it stands in the stack, and run by the entry point's
        // runner once its #[Authorize] checks have passed (EntryGates).
        return EntryGates::defer(new readonly class ($handler, $attribute) implements Middleware {
            public function __construct(private AttributeMiddleware $handler, private MiddlewareAttribute $attribute) {}

            public function handle(Request $request, \Closure $next): Response
            {
                return $this->handler->handle($this->attribute, $request, $next);
            }
        });
    }

    /**
     * Runs every gate in $middleware -- the references among it; a class
     * name is no gate -- as a check, now: where no request runs the entry
     * point, as a queued export's or import's job, which asks again what
     * its start asked. A gate lets it on by calling `$next`; one that
     * throws refuses with what it threw, and one that answers instead is a
     * refusal too, with nobody to hand its answer to.
     *
     * The request is $request, or one that carries nothing -- no header, no
     * cookie, no session -- so a gate that reads the request finds nothing
     * there, and refuses: it fails closed.
     *
     * @param list<string> $middleware an entry point's compiled middleware
     * @param \Closure(class-string): object $resolver
     * @throws HttpException as a gate refuses
     * @throws \LogicException for a reference that points at nothing, or a middleware that cannot be built
     */
    public static function check(array $middleware, \Closure $resolver, ?Request $request = null): void
    {
        $request ??= new Request('POST', '/');
        foreach ($middleware as $name) {
            if (!self::isReference($name)) {
                continue;
            }
            [$attribute, $handler] = self::handler($name, $resolver);
            $pass = Response::make(204);
            $answer = $handler->handle($attribute, $request, static fn (Request $passed): Response => $pass);
            if ($answer !== $pass) {
                throw new HttpException(
                    $answer->status >= 400 ? $answer->status : 403,
                    'A gate on this entry point answered rather than let it on: ' . $attribute::class . '.',
                    'gate_refused',
                );
            }
        }
    }

    /**
     * The attribute a reference points at, and its middleware, built.
     *
     * @param \Closure(class-string): object $resolver
     * @return array{MiddlewareAttribute, AttributeMiddleware}
     */
    private static function handler(string $reference, \Closure $resolver): array
    {
        $attribute = self::attribute($reference);
        $class = $attribute->middleware();
        $declared = '#[' . $attribute::class . '] on ' . substr($reference, strpos($reference, '@') + 1, strrpos($reference, '#') - strpos($reference, '@') - 1);
        if (!class_exists($class)) {
            throw new \LogicException("{$declared} is enforced by {$class}, which is no class.");
        }

        try {
            $handler = $resolver($class);
        } catch (\Throwable $e) {
            throw new \LogicException(
                "{$declared} is enforced by {$class}, which could not be built, so the request is refused: {$e->getMessage()} "
                . 'Register what it needs with the container, as its package says.',
                0,
                $e,
            );
        }
        if (!$handler instanceof AttributeMiddleware) {
            throw new \LogicException("{$declared} names {$class} as its middleware, and the resolver built " . get_debug_type($handler) . ', which is no ' . AttributeMiddleware::class . '.');
        }

        return [$attribute, $handler];
    }

    /**
     * @param \ReflectionClass<object> $class
     * @return list<\ReflectionAttribute<MiddlewareAttribute>>
     */
    private static function attributesAt(\ReflectionClass $class, ?string $method): array
    {
        if ($method === null) {
            return ClassAttributes::of($class, MiddlewareAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);
        }

        return $class->hasMethod($method)
            ? $class->getMethod($method)->getAttributes(MiddlewareAttribute::class, \ReflectionAttribute::IS_INSTANCEOF)
            : [];
    }
}
