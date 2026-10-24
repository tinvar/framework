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
     * The key a #[Once] field is sent under, holding its once key: with the
     * value, how long it may be claimed and its tags -- or, when the request
     * says the browser holds it, `held` and nothing else. ADR 0020.
     */
    public const string ONCE_KEY = '$once';

    /** The key a #[Merge] field is sent under, holding its dot path. */
    public const string MERGE_KEY = '$merge';

    /** The key a Scroll is sent under, holding its dot path. */
    public const string SCROLL_KEY = '$scroll';

    /**
     * The key an #[Optional] field nobody asked for is sent under, holding
     * the dot path a partial reload names it by: `{"$optional": "page.history"}`.
     * ADR 0045.
     */
    public const string OPTIONAL_KEY = '$optional';

    /** What a matchOn may name: one property of an item. */
    private const string PROPERTY = '/^[A-Za-z_][A-Za-z0-9_]*\z/';

    /**
     * @param (\Closure(\Throwable, ?string): string)|null $report logs what a
     *        #[Rescue] field threw and returns the digest it was logged
     *        under -- the Kernel's report(). Without one the failure goes to
     *        PHP's own error log: a field the page is allowed to lose must
     *        still be found by whoever runs the site.
     */
    public function __construct(private readonly ?\Closure $report = null) {}

    /**
     * @param PayloadContext|null $context what the request says the browser holds, for
     *        #[Once], #[Merge] and Scroll fields; none, for anything but a page render
     * @param string $segment the route segment the value is the data of -- a layout's key or
     *        `page` -- which roots the dot path a merged field is known to the browser by
     */
    public function encode(mixed $value, ?PayloadContext $context = null, string $segment = '$'): mixed
    {
        // Threaded through the walk rather than kept on the encoder: one
        // encoder serves every query call of a render, each in its own fiber,
        // and a fiber suspends mid-walk on a relation read while the next
        // one encodes.
        return $this->walk($value, '$', 0, [], new EncodeScope($context ?? PayloadContext::none(), $segment));
    }

    /** @param array<int, true> $seen object ids on the current path */
    private function walk(mixed $value, string $path, int $depth, array $seen, EncodeScope $scope): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw DataBoundaryException::tooDeep($path, self::MAX_DEPTH);
        }

        return match (true) {
            $value === null, \is_bool($value), \is_int($value), \is_float($value), \is_string($value) => $value,
            \is_array($value) => $this->walkArray($value, $path, $depth, $seen, $scope),
            $value instanceof Bin => $value,
            $value instanceof Lazy => $this->walk($value->resolve(), $path, $depth, $seen, $scope),
            $value instanceof Data => $this->walkData($value, $path, $depth, $seen, $scope),
            $value instanceof Scroll => $this->walkScroll($value, $path, $depth, $seen, $scope),
            \is_object($value) => throw DataBoundaryException::notData($value, $path),
            default => throw DataBoundaryException::badResource($path, get_debug_type($value)),
        };
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<int, true>        $seen
     * @return array<array-key, mixed>
     */
    private function walkArray(array $value, string $path, int $depth, array $seen, EncodeScope $scope): array
    {
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $i => $item) {
                $out[] = $this->walk($item, "{$path}[{$i}]", $depth + 1, $seen, $scope);
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
            $out[$k] = $this->walk($item, "{$path}.{$k}", $depth + 1, $seen, $scope);
        }

        return $out;
    }

    /**
     * @param array<int, true> $seen
     * @return array<string, mixed>
     */
    private function walkData(Data $value, string $path, int $depth, array $seen, EncodeScope $scope): array
    {
        $id = spl_object_id($value);
        if (isset($seen[$id])) {
            throw DataBoundaryException::cycle($path, $value::class);
        }
        $seen[$id] = true;

        $rescued = DataShape::rescued($value::class);
        $once = DataShape::once($value::class);
        $merged = DataShape::merged($value::class);
        $optional = DataShape::optional($value::class);

        // An #[Always] field decides which segments a partial reload renders,
        // and that is decided from the Data a loader returned, before any of
        // it is encoded. Anywhere deeper the mark could never take effect, so
        // it is refused rather than ignored.
        if ($path !== '$' && DataShape::always($value::class) !== []) {
            throw new \LogicException(
                $value::class . " is met at {$path} and has an #[Always] field, which only the Data a layout's "
                . "or page's loader returns may have: it is what says the loader's segment is rendered on every "
                . 'partial reload. Move the field to that Data class.',
            );
        }

        // The key each once field is sent and held under, naming its
        // subject. Worked out before any field is read: a held one is never
        // read at all.
        $keys = [];
        $unnamed = null;
        foreach ($once as $name => $shape) {
            $keys[$name] = $this->onceKey($value, $name, $shape, $path, $scope);
            if ($shape['identity'] === []) {
                $unnamed ??= $name;
            }
        }
        if ($unnamed !== null) {
            $scope->meetUnnamed($value::class, $unnamed, $once[$unnamed]['key'], $path);
        }

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

            // Optional and not asked for by name: the resolver is never run,
            // and the field is sent as the path a partial reload would name
            // it by. Before anything else, a held once key included -- an
            // optional field is not there to be held until it is asked for.
            if (isset($optional[$name])) {
                $dot = $scope->dotPath("{$path}.{$name}");
                if (!$scope->context->requests($dot)) {
                    $out[$name] = [self::OPTIONAL_KEY => $dot];
                    continue;
                }
            }

            // Held by the browser: the resolver is never run and nothing is
            // sent but the key, so the render skips what it would have drawn
            // from it. Checked before the field is read at all.
            if (isset($keys[$name]) && $scope->context->holds($keys[$name])) {
                $out[$name] = [self::ONCE_KEY => $keys[$name], 'held' => true];
                continue;
            }

            $field = $property->getValue($value);
            $fieldPath = "{$path}.{$name}";
            $failed = false;
            $encoded = isset($rescued[$name]) && $field instanceof Lazy
                ? $this->rescue($field, $fieldPath, $depth + 1, $seen, $scope, $failed)
                : $this->walk($field, $fieldPath, $depth + 1, $seen, $scope);

            if (isset($merged[$name]) && \is_array($encoded)) {
                $encoded = $this->merged($merged[$name], $encoded, $fieldPath, $scope);
            }

            if (!isset($once[$name], $keys[$name])) {
                $out[$name] = $encoded;
                continue;
            }

            $out[$name] = [
                self::ONCE_KEY => $keys[$name],
                'ttl' => $once[$name]['ttl'],
                'tags' => $once[$name]['tags'],
                'value' => $encoded,
            ];
            // A rescued failure is not what the field renders, only what it
            // rendered this once: kept and claimed, a database blip would be
            // what the browser put back for the field's whole ttl, or the
            // whole session. Sent to be shown and not kept, so the next
            // visit asks for it again (ADR 0020).
            if ($failed) {
                $out[$name]['keep'] = false;
            }
        }

        return $out;
    }

    /**
     * The key a once field is sent under: what was rendered, and for whom.
     *
     * A default key ends in the page the request asked for, so the summary
     * kept from `/customers/1` is never claimed for `/customers/2`. A named
     * key's `{property}` parts become digests of the instance's values, so
     * each row of a list is kept apart. A key that names no subject of its
     * own -- either kind, without braces -- may be met once per payload: a
     * second instance would be kept under it too, and shown in the first's
     * place (ADR 0020).
     *
     * @param array{key: string, ttl: int|null, tags: list<string>, scoped: bool, identity: list<string>} $shape
     */
    private function onceKey(Data $value, string $name, array $shape, string $path, EncodeScope $scope): string
    {
        if ($shape['identity'] === []) {
            return $shape['scoped'] ? "{$shape['key']}:{$scope->page()}" : $shape['key'];
        }

        return (string) preg_replace_callback('/\{([^{}]*)\}/', static function (array $named) use ($value, $name, $path): string {
            $property = new \ReflectionProperty($value, $named[1]);
            if (!$property->isInitialized($value)) {
                throw new \LogicException(
                    "The once field at {$path}.{$name} names its subject by '{$named[1]}', which is not set.",
                );
            }

            $subject = $property->getValue($value);
            \assert(\is_string($subject) || \is_int($subject));

            return PayloadContext::digest((string) $subject);
        }, $shape['key']);
    }

    /**
     * A #[Merge] field: its items, and what the browser needs to put them
     * with those it shows -- where, matched on what, and whether this
     * response replaces them instead.
     *
     * @param array<array-key, mixed> $items already encoded
     * @return array<string, mixed>
     */
    private function merged(\Tinvar\Attributes\Merge $merge, array $items, string $path, EncodeScope $scope): array
    {
        $dot = $scope->dotPath($path);

        return [
            self::MERGE_KEY => $dot,
            'mode' => $merge->prepend ? 'prepend' : 'append',
            'reset' => $scope->context->resets($dot),
            'matchOn' => self::matchOn($merge->matchOn, $path),
            'items' => array_values($items),
        ];
    }

    /**
     * One page of a Scroll: its items, which page they are, the pages either
     * side, and the same reset a #[Merge] field carries.
     *
     * @param Scroll<mixed>    $scroll
     * @param array<int, true> $seen
     * @return array<string, mixed>
     */
    private function walkScroll(Scroll $scroll, string $path, int $depth, array $seen, EncodeScope $scope): array
    {
        $dot = $scope->dotPath($path);

        // Always a list, whatever keys it was built with: the items are in
        // page order, and a map would cross as an object.
        $items = [];
        foreach ($scroll->items as $i => $item) {
            $items[] = $this->walk($item, "{$path}.items[{$i}]", $depth + 1, $seen, $scope);
        }

        return [
            self::SCROLL_KEY => $dot,
            'reset' => $scope->context->resets($dot),
            'page' => $scroll->page,
            'previous' => $scroll->previous,
            'next' => $scroll->next,
            'total' => $scroll->total,
            'perPage' => $scroll->perPage,
            'pageName' => $scroll->pageName,
            'matchOn' => self::matchOn($scroll->matchOn, $path),
            'items' => $items,
        ];
    }

    private static function matchOn(?string $property, string $path): ?string
    {
        if ($property !== null && preg_match(self::PROPERTY, $property) !== 1) {
            throw new \LogicException("The list at {$path} is matched on '{$property}', which is not a property name.");
        }

        return $property;
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
     * @param bool $failed set when the marker is what is returned
     */
    private function rescue(Lazy $field, string $path, int $depth, array $seen, EncodeScope $scope, bool &$failed): mixed
    {
        try {
            $value = $field->resolve();
        } catch (\Throwable $e) {
            $failed = true;

            return [self::RESCUED_KEY => $this->reported($e)];
        }

        return $this->walk($value, $path, $depth, $seen, $scope);
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
