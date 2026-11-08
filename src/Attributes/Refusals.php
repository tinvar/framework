<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * The class that writes an endpoint's refusals (ADR 0188).
 *
 *     #[WithoutSession(reason: '...'), Domain('api.{root}'), Refusals(ApiRefusals::class)]
 *
 * An API whose clients read their own error shape -- a command-line client
 * waiting what `retry_after` says -- gets every refusal of its endpoints in
 * it: a throttle's 429, by address or by account; authentication's 401;
 * a 403, 404, 405 or 413 from anything in the endpoint's stack or the
 * endpoint itself. The class implements Tinvar\Http\RefusalWriter and is
 * built by the application's resolver. A failed validation keeps its own
 * 422 with its field errors, and a 500 stays the framework's.
 *
 * On the class -- or a class it extends, or a trait it uses -- it covers
 * each endpoint in the class; on the method it replaces the class's. Only
 * endpoints read it: an action holder carrying it is refused.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Refusals
{
    /** @param class-string $writer a Tinvar\Http\RefusalWriter */
    public function __construct(public string $writer) {}

    /** The writer an endpoint names: its method's, else its class's, the nearest a trait or an ancestor declares; null for none. */
    public static function declaredOn(string $class, string $method): ?self
    {
        if (!class_exists($class)) {
            return null;
        }
        $reflection = new \ReflectionClass($class);
        $onClass = ClassAttributes::of($reflection, self::class);
        $attribute = ($reflection->hasMethod($method) ? $reflection->getMethod($method)->getAttributes(self::class)[0] ?? null : null)
            ?? ($onClass === [] ? null : $onClass[array_key_last($onClass)]);

        return $attribute?->newInstance();
    }
}
