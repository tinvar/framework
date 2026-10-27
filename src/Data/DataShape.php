<?php

declare(strict_types=1);

namespace Tinvar\Data;

use Tinvar\Attributes\Always;
use Tinvar\Attributes\Merge;
use Tinvar\Attributes\Once;
use Tinvar\Attributes\Optional;
use Tinvar\Attributes\Rescue;
use Tinvar\Cache\Duration;
use Tinvar\Support\Lazy;
use Tinvar\Support\KnownAttributes;

/**
 * The fields of a Data class that cross the boundary.
 *
 * One definition, used by both the encoder and codegen. If these disagreed, the
 * generated TypeScript would describe a payload the encoder does not produce,
 * which is the one failure mode end-to-end types exist to prevent.
 */
final class DataShape
{
    /** @var array<class-string, list<\ReflectionProperty>> */
    private static array $cache = [];

    /** @var array<class-string, array<string, true>> */
    private static array $rescued = [];

    /** @var array<class-string, array<string, array{key: string, ttl: int|null, tags: list<string>, scoped: bool, identity: list<string>}>> */
    private static array $once = [];

    /** @var array<class-string, array<string, Merge>> */
    private static array $merged = [];

    /** @var array<class-string, array<string, true>> */
    private static array $optional = [];

    /** @var array<class-string, array<string, true>> */
    private static array $always = [];

    /**
     * Public, non-static properties in declaration order.
     *
     * Private state is not payload, which keeps the boundary narrow by
     * construction. Order matters: it is the order of the payload rows and of
     * the generated interface.
     *
     * @param class-string $class
     * @return list<\ReflectionProperty>
     */
    public static function fieldsOf(string $class): array
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        // A Tinvar attribute naming no class would be matched by nothing, and
        // what it declares would not hold (KnownAttributes).
        $reflection = new \ReflectionClass($class);
        KnownAttributes::check($reflection);

        $fields = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic()) {
                $fields[] = $property;
            }
        }

        return self::$cache[$class] = $fields;
    }

    /**
     * The fields marked #[Rescue], by name.
     *
     * Here for the same reason fieldsOf() is: the encoder decides which
     * fields may be sent as a Rescued marker, and codegen decides which
     * types say so. Read in two places, they could disagree, and a field
     * typed `T` would arrive as a marker.
     *
     * @param class-string $class
     * @return array<string, true>
     */
    public static function rescued(string $class): array
    {
        if (isset(self::$rescued[$class])) {
            return self::$rescued[$class];
        }

        $rescued = [];
        foreach (self::fieldsOf($class) as $property) {
            if ($property->getAttributes(Rescue::class) !== []) {
                $rescued[$property->getName()] = true;
            }
        }

        return self::$rescued[$class] = $rescued;
    }

    /**
     * The fields marked #[Once], by name: the key the browser keeps each
     * under, how many seconds it may go on claiming it (null for as long as
     * the session lasts), and the tags that make it stop.
     *
     * Read here for the reason rescued() is: the encoder sends these as
     * once fields and codegen types them so, and read in two places they
     * could disagree. A key or duration that cannot be used is refused when
     * the class is first read, not when some request finally sends it.
     *
     * A key names rendered content for one subject, so the key the encoder
     * sends always says what the subject is (ADR 0020):
     *
     *   - No key given (`scoped`): the class's short name and the field's,
     *     scoped to the page the request asked for -- route, parameters and
     *     query -- whose digest the encoder appends. `/customers/1` and
     *     `/customers/2` keep their summaries apart.
     *   - A key given: content the application says is the same wherever it
     *     is claimed, a country list. It may name its subject from the
     *     class's own properties, in braces (`identity`) --
     *     `customer.badge:{id}` -- each replaced by a digest of the value:
     *     how a class used for a list's rows keeps each row's apart.
     *
     * @param class-string $class
     * @return array<string, array{key: string, ttl: int|null, tags: list<string>, scoped: bool, identity: list<string>}>
     */
    public static function once(string $class): array
    {
        if (isset(self::$once[$class])) {
            return self::$once[$class];
        }

        $short = (new \ReflectionClass($class))->getShortName();
        $fields = [];
        foreach (self::fieldsOf($class) as $property) {
            $fields[$property->getName()] = $property;
        }

        $once = [];
        foreach ($fields as $name => $property) {
            $attribute = $property->getAttributes(Once::class)[0] ?? null;
            if ($attribute === null) {
                continue;
            }

            $declared = $attribute->newInstance();
            $key = $declared->key ?? "{$short}.{$name}";
            $identity = [];
            if ($declared->key !== null) {
                preg_match_all('/\{([^{}]*)\}/', $key, $named);
                foreach ($named[1] as $one) {
                    $identity[] = self::identity($class, $name, $key, $one, $fields);
                }
            }

            // The key as the encoder will send it, each subject as long as
            // its digest: one a header cannot carry would have the whole
            // X-Have-Once list refused, every key in it with it.
            $digest = str_repeat('x', PayloadContext::DIGEST_LENGTH);
            $sent = $declared->key === null ? "{$key}:{$digest}" : (string) preg_replace('/\{[^{}]*\}/', $digest, $key);
            if (preg_match(PayloadContext::KEY, $sent) !== 1) {
                throw new \LogicException(
                    "{$class}::\${$name} is #[Once] under the key '{$key}', which a request header cannot carry. "
                    . 'Use letters, digits and . _ : / -, up to 128 of them, counting each {property} as '
                    . PayloadContext::DIGEST_LENGTH . ' and, for a default key, ' . (PayloadContext::DIGEST_LENGTH + 1)
                    . ' more for the page.',
                );
            }

            $tags = [];
            foreach ($declared->tags as $tag) {
                if (trim($tag) === '' || str_contains($tag, ',')) {
                    throw new \LogicException("{$class}::\${$name} names a #[Once] tag that is not one tag.");
                }
                $tags[] = trim($tag);
            }

            $once[$name] = [
                'key' => $key,
                'ttl' => $declared->until === null ? null : Duration::seconds($declared->until),
                'tags' => $tags,
                'scoped' => $declared->key === null,
                'identity' => array_values(array_unique($identity)),
            ];
        }

        return self::$once[$class] = $once;
    }

    /**
     * A property a once key names its subject by: one of the class's own
     * fields, always a string or an integer -- what a row is told apart by,
     * read before the field it keys is.
     *
     * @param class-string $class
     * @param array<string, \ReflectionProperty> $fields
     */
    private static function identity(string $class, string $field, string $key, string $named, array $fields): string
    {
        $property = $fields[$named] ?? null;
        $type = $property?->getType();
        if ($property === null
            || !$type instanceof \ReflectionNamedType
            || $type->allowsNull()
            || !\in_array($type->getName(), ['string', 'int'], true)
            || $property->getAttributes(Once::class) !== []) {
            throw new \LogicException(
                "{$class}::\${$field} is #[Once] under the key '{$key}', which names its subject by '{$named}'. "
                . "That must be a public property of {$class} typed string or int, not nullable and not #[Once] itself.",
            );
        }

        return $named;
    }

    /**
     * The fields marked #[Merge], by name. Only a list can be merged into
     * one already shown, so a mark on anything else is refused here.
     *
     * @param class-string $class
     * @return array<string, Merge>
     */
    public static function merged(string $class): array
    {
        if (isset(self::$merged[$class])) {
            return self::$merged[$class];
        }

        $merged = [];
        foreach (self::fieldsOf($class) as $property) {
            $attribute = $property->getAttributes(Merge::class)[0] ?? null;
            if ($attribute === null) {
                continue;
            }

            $type = $property->getType();
            if (!$type instanceof \ReflectionNamedType || $type->getName() !== 'array') {
                throw new \LogicException(
                    "{$class}::\${$property->getName()} is #[Merge], which only a list field can be. "
                    . 'A paginated list is a Scroll, which merges by page without it.',
                );
            }

            $merged[$property->getName()] = $attribute->newInstance();
        }

        return self::$merged[$class] = $merged;
    }

    /**
     * The fields marked #[Optional], by name: resolved only for a partial
     * reload that names them (ADR 0045).
     *
     * Read here for the reason rescued() is: the encoder sends these as an
     * Unrequested marker and codegen types them so. Only a Lazy field can be
     * optional -- any other value is produced before encoding, so leaving it
     * out would skip nothing it cost -- and a field cannot be both optional
     * and #[Always]: one is never sent unless asked for, the other always is.
     *
     * @param class-string $class
     * @return array<string, true>
     */
    public static function optional(string $class): array
    {
        if (isset(self::$optional[$class])) {
            return self::$optional[$class];
        }

        $optional = [];
        foreach (self::fieldsOf($class) as $property) {
            if ($property->getAttributes(Optional::class) === []) {
                continue;
            }

            $where = "{$class}::\${$property->getName()}";
            $type = $property->getType();
            if (!$type instanceof \ReflectionNamedType || !is_a($type->getName(), Lazy::class, true)) {
                throw new \LogicException(
                    "{$where} is #[Optional], which only a Lazy field can be: its value is produced "
                    . 'before encoding, so there would be nothing left to skip.',
                );
            }
            if ($property->getAttributes(Always::class) !== []) {
                throw new \LogicException("{$where} is both #[Optional] and #[Always]: never sent unless asked for, and always sent.");
            }

            $optional[$property->getName()] = true;
        }

        return self::$optional[$class] = $optional;
    }

    /**
     * The fields marked #[Always], by name. A segment whose data has one is
     * re-rendered on every partial reload of its page (ADR 0045), so this is
     * read off the Data class a loader returned, before anything renders.
     *
     * @param class-string $class
     * @return array<string, true>
     */
    public static function always(string $class): array
    {
        if (isset(self::$always[$class])) {
            return self::$always[$class];
        }

        $always = [];
        foreach (self::fieldsOf($class) as $property) {
            if ($property->getAttributes(Always::class) !== []) {
                $always[$property->getName()] = true;
            }
        }

        return self::$always[$class] = $always;
    }
}
