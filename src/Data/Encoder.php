<?php

declare(strict_types=1);

namespace Tinvar\Data;

use Tinvar\Codec\Bin;
use Tinvar\Support\Digest;

/**
 * Converts a loader or query return value into the scalars, lists and maps the
 * socket codec can carry -- and refuses everything else.
 *
 * tinvar-spec.md, "Renderer hardening":
 *   "The PHP->renderer encoder accepts only Data objects, scalars, lists and
 *    maps, and throws on any other object, including models, in every
 *    environment."
 *
 * The check is structural and recursive, so a model hidden inside an array, a
 * map value or a Lazy field is caught just as a top-level one is.
 */
final class Encoder
{
    /** Matches the JSON depth cap the spec sets on the action and query endpoints. */
    public const int MAX_DEPTH = 32;

    /** The one map key a JavaScript object cannot hold as plain data. */
    public const string RESERVED_KEY = '__proto__';

    /**
     * The key of the marker a rescued field is sent as: `{"$rescued": digest}`.
     * `$` because no PHP property can be named with it, so no Data object
     * encodes to a map that looks like one.
     */
    public const string RESCUED_KEY = '$rescued';

    /**
     * @param (\Closure(\Throwable, ?string): string)|null $report logs what a
     *        #[Rescue] field threw and returns the digest it was logged
     *        under -- the Kernel's report(). Without one the failure goes to
     *        PHP's own error log: a field the page is allowed to lose must
     *        still be found by whoever runs the site.
     */
    public function __construct(private readonly ?\Closure $report = null) {}

    public function encode(mixed $value): mixed
    {
        return $this->walk($value, '$', 0, []);
    }

    /** @param array<int, true> $seen object ids on the current path */
    private function walk(mixed $value, string $path, int $depth, array $seen): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw DataBoundaryException::tooDeep($path, self::MAX_DEPTH);
        }

        return match (true) {
            $value === null, \is_bool($value), \is_int($value), \is_float($value), \is_string($value) => $value,
            \is_array($value) => $this->walkArray($value, $path, $depth, $seen),
            $value instanceof Bin => $value,
            $value instanceof Lazy => $this->walk($value->resolve(), $path, $depth, $seen),
            $value instanceof Data => $this->walkData($value, $path, $depth, $seen),
            \is_object($value) => throw DataBoundaryException::notData($value, $path),
            default => throw DataBoundaryException::badResource($path, get_debug_type($value)),
        };
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<int, true>        $seen
     * @return array<array-key, mixed>
     */
    private function walkArray(array $value, string $path, int $depth, array $seen): array
    {
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $i => $item) {
                $out[] = $this->walk($item, "{$path}[{$i}]", $depth + 1, $seen);
            }

            return $out;
        }

        $out = [];
        foreach ($value as $k => $item) {
            // Map keys are often user data, a settings map saved from a form.
            // The renderer's MessagePack decoder throws on this one key, so it
            // would fail every render of that data for good, and any code that
            // copies the map by assignment would set a prototype instead.
            if ($k === self::RESERVED_KEY) {
                throw DataBoundaryException::reservedKey($path, $k);
            }
            $out[$k] = $this->walk($item, "{$path}.{$k}", $depth + 1, $seen);
        }

        return $out;
    }

    /**
     * @param array<int, true> $seen
     * @return array<string, mixed>
     */
    private function walkData(Data $value, string $path, int $depth, array $seen): array
    {
        $id = spl_object_id($value);
        if (isset($seen[$id])) {
            throw DataBoundaryException::cycle($path, $value::class);
        }
        $seen[$id] = true;

        $rescued = DataShape::rescued($value::class);

        $out = [];
        foreach (DataShape::fieldsOf($value::class) as $property) {
            $name = $property->getName();
            if (!$property->isVirtual() && !$property->isInitialized($value)) {
                // One that may be null is sent as null, which is what its
                // generated type -- `T | null`, from the same DataShape --
                // says it can be. Left out, a component testing for null
                // found undefined and a key its type promised was missing.
                // One that may not be null has no value it could be sent
                // as, and stays absent.
                if ($property->getType()?->allowsNull() === true) {
                    $out[$name] = null;
                }
                continue;
            }
            $field = $property->getValue($value);
            $out[$name] = isset($rescued[$name]) && $field instanceof Lazy
                ? $this->rescue($field, "{$path}.{$name}", $depth + 1, $seen)
                : $this->walk($field, "{$path}.{$name}", $depth + 1, $seen);
        }

        return $out;
    }

    /**
     * A #[Rescue] field: its value, or -- if producing it threw -- the
     * marker, with the failure reported.
     *
     * Only the resolver is rescued. What it returns still crosses on the
     * boundary's terms: a model in it is a bug in the field, not a failure
     * to load it, and is refused here as it would be anywhere else -- in
     * every environment, as the hardening rules say.
     *
     * @param array<int, true> $seen
     */
    private function rescue(Lazy $field, string $path, int $depth, array $seen): mixed
    {
        try {
            $value = $field->resolve();
        } catch (\Throwable $e) {
            return [self::RESCUED_KEY => $this->reported($e)];
        }

        return $this->walk($value, $path, $depth, $seen);
    }

    private function reported(\Throwable $e): string
    {
        if ($this->report !== null) {
            return ($this->report)($e, null);
        }

        $digest = Digest::of($e);
        error_log(sprintf('[tinvar] rescued a field that threw %s: %s [%s]', $e::class, $e->getMessage(), $digest));

        return $digest;
    }
}
