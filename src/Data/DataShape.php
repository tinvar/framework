<?php

declare(strict_types=1);

namespace Tinvar\Data;

use Tinvar\Attributes\Once;
use Tinvar\Attributes\Rescue;
use Tinvar\Cache\Duration;

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

    /** @var array<class-string, array<string, array{key: string, ttl: int|null, tags: list<string>}>> */
    private static array $once = [];

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

        $fields = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
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
     * @param class-string $class
     * @return array<string, array{key: string, ttl: int|null, tags: list<string>}>
     */
    public static function once(string $class): array
    {
        if (isset(self::$once[$class])) {
            return self::$once[$class];
        }

        $short = (new \ReflectionClass($class))->getShortName();
        $once = [];
        foreach (self::fieldsOf($class) as $property) {
            $attribute = $property->getAttributes(Once::class)[0] ?? null;
            if ($attribute === null) {
                continue;
            }

            $declared = $attribute->newInstance();
            $name = $property->getName();
            $key = $declared->key ?? "{$short}.{$name}";
            if (preg_match(PayloadContext::KEY, $key) !== 1) {
                throw new \LogicException(
                    "{$class}::\${$name} is #[Once] under the key '{$key}', which a request header cannot carry. "
                    . 'Use letters, digits and . _ : / -, up to 128 of them.',
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
            ];
        }

        return self::$once[$class] = $once;
    }
}
