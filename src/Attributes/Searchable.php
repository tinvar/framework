<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A model whose rows full-text search finds (ADR 0150).
 *
 *     #[Table('products'), Searchable(fields: ['name', 'summary', 'description'], weights: ['name' => 3, 'summary' => 2])]
 *     final class Product extends Model { ... }
 *
 * `fields` are the model's own properties searched, each a #[Column] of
 * text, a number or a backed enum; `weights` rank a match in one field
 * above a match in another, every field 1 unless named, at most four
 * different weights in all -- the four classes Postgres ranks by, which
 * every backend holds to so a model ranks alike on each.
 *
 * The index follows every write the ORM makes to the model, once its
 * transaction commits, and a search reads its results again through the
 * ORM, so tenant scoping holds on both sides (Tinvar\Search\Search). Read
 * when the application's search is built, which refuses a field the model
 * does not have, naming it, before any request is served.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Searchable
{
    /** The most fields one model searches. */
    public const int MAX_FIELDS = 16;

    /** The most different weights: Postgres's four ranking classes, A to D. */
    public const int MAX_WEIGHTS = 4;

    /** @var list<string> the properties searched */
    public array $fields;

    /** @var array<string, int|float> field => weight; a field not named weighs 1 */
    public array $weights;

    /**
     * @param array<array-key, mixed> $fields the properties searched, as a list
     * @param array<array-key, mixed> $weights field => weight, above 0 and at most 100; 1 unless named
     */
    public function __construct(array $fields, array $weights = [])
    {
        if ($fields === [] || !array_is_list($fields) || \count($fields) > self::MAX_FIELDS) {
            throw new \InvalidArgumentException('#[Searchable] names between 1 and ' . self::MAX_FIELDS . ' fields, as a list of the model\'s properties.');
        }
        foreach ($fields as $field) {
            if (!\is_string($field) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $field) !== 1) {
                throw new \InvalidArgumentException('#[Searchable] fields are property names: ' . var_export($field, true) . ' is not one.');
            }
        }
        if (\count(array_unique($fields)) !== \count($fields)) {
            throw new \InvalidArgumentException('#[Searchable] names a field twice: name each once, and weigh it in weights.');
        }
        foreach ($weights as $field => $weight) {
            if (!\is_string($field) || !\in_array($field, $fields, true)) {
                throw new \InvalidArgumentException("#[Searchable] weighs '{$field}', which is not one of its fields: add it to fields, or take its weight out.");
            }
            if ((!\is_int($weight) && !\is_float($weight)) || !is_finite((float) $weight) || $weight <= 0 || $weight > 100) {
                throw new \InvalidArgumentException("#[Searchable] weighs '{$field}' " . var_export($weight, true) . ': a weight is a number above 0 and at most 100.');
            }
        }
        $distinct = array_unique(array_map(floatval(...), [...array_values($weights), ...(\count($weights) < \count($fields) ? [1.0] : [])]));
        if (\count($distinct) > self::MAX_WEIGHTS) {
            throw new \InvalidArgumentException('#[Searchable] uses ' . \count($distinct) . ' different weights, counting the 1 a field takes unweighed; rank by at most ' . self::MAX_WEIGHTS . ', the classes Postgres ranks by.');
        }
        $this->fields = $fields;
        $this->weights = $weights;
    }

    /** A field's weight: as weights names it, or 1. */
    public function weightOf(string $field): float
    {
        return (float) ($this->weights[$field] ?? 1);
    }
}
