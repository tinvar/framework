<?php

declare(strict_types=1);

namespace Tinvar\Cache;

use Tinvar\Codec\Bin;
use Tinvar\Codec\CodecException;
use Tinvar\Codec\MessagePack;
use Tinvar\Data\Encoder;

/**
 * A cached value as PdoStore keeps it in its table.
 *
 * What is cached is the encoder's output (QueryRunner::settle()): null,
 * booleans, integers, floats, strings -- any bytes, not only UTF-8 -- lists,
 * maps with integer or string keys, and Bin for bytes that must reach the
 * renderer as MessagePack `bin`. The table is a trust boundary: anyone who
 * can write it chooses what comes back out. So never serialize(), whose
 * unserialize() builds whatever objects the bytes name, and never anything
 * this cannot check: the framework's own MessagePack, decoded by the
 * pure-PHP decoder the renderer's frames are -- scalars and arrays only,
 * with its depth and element caps -- and then held to the shape the encoder
 * produces.
 *
 * MessagePack decodes `bin` and `str` alike, as PHP strings, so a Bin is
 * written as its bytes and its place in the value listed beside it: on the
 * way back, the strings at those places, and only those, become Bin again.
 * A marker inside the value -- `{"$bin": ...}`, as JSON lines carry one --
 * would be a map the application's own data could spell.
 *
 * The bytes are a list: [format, value, the Bins' places, the tags' versions
 * as [name, version] pairs]. Anything that is not exactly that, or whose
 * value is not something the encoder could have produced, is refused: null,
 * which the store treats as a miss.
 *
 * @internal for PdoStore
 */
final class Payload
{
    /** Bumped if the layout changes, so rows written before are refused, not misread. */
    private const int FORMAT = 1;

    /**
     * Tags an entry is kept with, at most. Each is a bound parameter when
     * the entry is read back, and Postgres and MySQL refuse a statement with
     * more than 65,535 -- SQLite, by default, 32,766.
     */
    public const int MAX_TAGS = 256;

    /**
     * The bytes for a value, or null for one this does not keep: an object
     * other than Bin, a resource, a map key the encoder refuses, a value
     * deeper than it allows, or more elements than the decoder would read
     * back -- one that could never be read is not worth writing.
     *
     * @param array<string, int> $versions each tag's name, and the version it had
     */
    public static function encode(mixed $value, array $versions): ?string
    {
        if (\count($versions) > self::MAX_TAGS) {
            return null;
        }

        $path = [];
        $bins = [];
        $elements = 0;
        if (!self::scan($value, $path, $bins, $elements, 0)) {
            return null;
        }

        // Counted as the decoder counts: each container's length, the
        // envelope's own included.
        $elements += 4 + \count($bins) + \count($versions) * 3;
        foreach ($bins as $place) {
            $elements += \count($place);
        }
        if ($elements > MessagePack::MAX_ELEMENTS) {
            return null;
        }

        // Each Bin's bytes in its place. Assigning through a reference
        // separates only the arrays on the way to it; the rest of the value
        // is shared, not copied.
        foreach ($bins as $place) {
            $node = &$value;
            foreach ($place as $step) {
                $node = &$node[$step];
            }
            \assert($node instanceof Bin);
            $node = $node->bytes;
            unset($node);
        }

        $pairs = [];
        foreach ($versions as $name => $version) {
            $pairs[] = [(string) $name, $version];
        }

        return MessagePack::encode([self::FORMAT, $value, $bins, $pairs]);
    }

    /**
     * The value and the tags' versions the bytes hold, or null for bytes
     * that are not a payload this wrote.
     *
     * @return array{value: mixed, versions: array<string, int>}|null
     */
    public static function decode(string $bytes): ?array
    {
        $offset = 0;
        try {
            $decoded = MessagePack::decode($bytes, $offset);
        } catch (CodecException) {
            return null;
        }

        // All of it, and nothing after: trailing bytes are not a payload.
        if ($offset !== \strlen($bytes)
            || !\is_array($decoded) || !array_is_list($decoded) || \count($decoded) !== 4
            || $decoded[0] !== self::FORMAT) {
            return null;
        }
        [, $value, $bins, $pairs] = $decoded;

        if (!\is_array($pairs) || !array_is_list($pairs) || \count($pairs) > self::MAX_TAGS
            || !\is_array($bins) || !array_is_list($bins)) {
            return null;
        }

        $versions = [];
        foreach ($pairs as $pair) {
            if (!\is_array($pair) || !array_is_list($pair) || \count($pair) !== 2
                || !\is_string($pair[0]) || !\is_int($pair[1]) || isset($versions[$pair[0]])) {
                return null;
            }
            $versions[$pair[0]] = $pair[1];
        }

        if (!self::plain($value, 0)) {
            return null;
        }

        foreach ($bins as $place) {
            if (!\is_array($place) || !array_is_list($place) || !self::wrap($value, $place)) {
                return null;
            }
        }

        return ['value' => $value, 'versions' => $versions];
    }

    /**
     * Walks a value to be written: whether it can be, where its Bins are,
     * and how many elements its containers hold.
     *
     * @param list<int|string> $path where the walk is, kept in place rather than copied per element
     * @param list<list<int|string>> $bins
     */
    private static function scan(mixed $value, array &$path, array &$bins, int &$elements, int $depth): bool
    {
        if ($depth > Encoder::MAX_DEPTH) {
            return false;
        }

        if ($value instanceof Bin) {
            $bins[] = $path;

            return true;
        }

        if (\is_array($value)) {
            $elements += \count($value);
            foreach ($value as $key => $item) {
                if ($key === Encoder::RESERVED_KEY) {
                    return false;
                }
                $path[] = $key;
                $kept = self::scan($item, $path, $bins, $elements, $depth + 1);
                array_pop($path);
                if (!$kept) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || \is_scalar($value);
    }

    /**
     * Whether a value read back is one the encoder could have produced: no
     * deeper than it allows, and no map key it refuses -- the renderer's
     * decoder throws on that key, so a row carrying one would fail every
     * render that read it. MessagePack decodes nothing but scalars and
     * arrays, so the types need no check.
     */
    private static function plain(mixed $value, int $depth): bool
    {
        if ($depth > Encoder::MAX_DEPTH) {
            return false;
        }
        if (!\is_array($value)) {
            return true;
        }

        foreach ($value as $key => $item) {
            if ($key === Encoder::RESERVED_KEY || !self::plain($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Makes the string at $place a Bin again; false when there is no string
     * there -- a place that leads nowhere, or one listed twice.
     *
     * @param list<mixed> $place
     */
    private static function wrap(mixed &$value, array $place): bool
    {
        $node = &$value;
        foreach ($place as $step) {
            if ((!\is_int($step) && !\is_string($step)) || !\is_array($node) || !\array_key_exists($step, $node)) {
                return false;
            }
            $node = &$node[$step];
        }

        if (!\is_string($node)) {
            return false;
        }
        $node = new Bin($node);

        return true;
    }
}
