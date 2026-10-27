<?php

declare(strict_types=1);

namespace Tinvar\Data;

use Tinvar\Attributes\FieldsOf;
use Tinvar\Attributes\From;
use Tinvar\Attributes\MapsFrom;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;

/**
 * The fields of a Data class a query may sort or filter by, from a request:
 * tinvar-spec.md, "Identifiers from users" -- "Sort, filter and column
 * fields are backed enums or #[In] lists generated from Data fields."
 *
 * A field counts when the Data class says which model it maps from
 * (#[MapsFrom]) and the field is mapped from the model's property of the
 * same name, which maps a column: what the page already shows, under the
 * name the query builder resolves. A column no Data field shows -- a
 * password hash, a stock count the page shows only as "in stock" -- is in
 * no list, so a request cannot sort by it and read its order back. A field
 * renamed with #[From] is left out too: its name is not the model's.
 *
 * Two ways to use the list, both closed sets:
 *
 *   - `#[FieldOf(ProductCardData::class)]` on a form request's string field:
 *     the #[In] list, generated from the Data's fields when the request is
 *     planned (Rules\FieldOf);
 *   - a string-backed enum marked `#[FieldsOf(ProductCardData::class)]`,
 *     read from a request with pick(): every case is checked to be one of
 *     the Data's fields before any is taken, so an enum that names a hidden
 *     column fails at its first use rather than answering it.
 *
 * Compiled once per class, as the ORM's metadata is.
 */
final class DataFields
{
    /** @var array<class-string, list<string>> */
    private static array $fields = [];

    /** @var array<class-string, true> */
    private static array $checkedEnums = [];

    /**
     * @param class-string $data
     * @return list<string> the field names, in the order the class declares them
     * @throws \LogicException for a class that is no Data, or names no model
     */
    public static function of(string $data): array
    {
        return self::$fields[$data] ??= self::compile($data);
    }

    /**
     * A case of $enum, read from a request value: the case it names, or
     * null for anything else -- a value that is not a string, or names no
     * case. The enum is held to its #[FieldsOf] first.
     *
     * @template E of \BackedEnum
     * @param class-string<E> $enum
     * @return E|null
     * @throws \LogicException for an enum with no #[FieldsOf], or a case that is not one of its fields
     */
    public static function pick(string $enum, mixed $requested): ?\BackedEnum
    {
        self::assertEnum($enum);

        return \is_string($requested) ? $enum::tryFrom($requested) : null;
    }

    /**
     * What is wrong with $enum as a list of a Data class's fields: one line
     * per problem, none when it is one. For `tinvar check`, and for pick().
     *
     * @param class-string $enum
     * @return list<string>
     */
    public static function problemsWith(string $enum): array
    {
        if (!enum_exists($enum)) {
            return ["{$enum} is not an enum."];
        }
        $attribute = (new \ReflectionEnum($enum))->getAttributes(FieldsOf::class)[0] ?? null;
        if ($attribute === null) {
            return ["{$enum} has no #[FieldsOf]: nothing says which Data class's fields it lists."];
        }
        if ((string) (new \ReflectionEnum($enum))->getBackingType() !== 'string') {
            return ["{$enum} is not backed by strings, and a field is named by one."];
        }

        $data = $attribute->newInstance()->data;
        try {
            $fields = self::of($data);
        } catch (\LogicException $e) {
            return [$e->getMessage()];
        }

        $problems = [];
        foreach ($enum::cases() as $case) {
            \assert($case instanceof \BackedEnum);
            if (!\in_array($case->value, $fields, true)) {
                $problems[] = "{$enum}::{$case->name} names '{$case->value}', which is not a field of {$data} mapped from a "
                    . 'column of the same name. Known: ' . (implode(', ', $fields) ?: 'none') . '.';
            }
        }

        return $problems;
    }

    /** @param class-string $enum */
    private static function assertEnum(string $enum): void
    {
        if (isset(self::$checkedEnums[$enum])) {
            return;
        }

        $problems = self::problemsWith($enum);
        if ($problems !== []) {
            throw new \LogicException(implode(' ', $problems));
        }

        self::$checkedEnums[$enum] = true;
    }

    /**
     * @param class-string $data
     * @return list<string>
     */
    private static function compile(string $data): array
    {
        if (!is_subclass_of($data, Data::class)) {
            throw new \LogicException("{$data} is not a Data class, so it has no fields to sort or filter by.");
        }

        $reflection = new \ReflectionClass($data);
        $maps = $reflection->getAttributes(MapsFrom::class)[0] ?? null;
        $model = $maps?->newInstance()->model;
        if ($model === null || !is_subclass_of($model, Model::class)) {
            throw new \LogicException(
                "{$data} names no model with #[MapsFrom], so none of its fields is known to be a column.",
            );
        }

        $columns = Metadata::for($model)->columns;
        $fields = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            if ($parameter->getAttributes(From::class) !== [] || !isset($columns[$name])) {
                continue;
            }
            $fields[] = $name;
        }

        return $fields;
    }
}
