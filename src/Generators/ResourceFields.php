<?php

declare(strict_types=1);

namespace Tinvar\Generators;

use Tinvar\Orm\Model;
use Tinvar\Support\Str;

/**
 * make:resource's --fields, read into ResourceField objects:
 *
 *     title:string,status:enum(Draft,Published),price:int,customer:belongsTo
 *
 * Each field is `name:type`, a `?` before the type for one that may be
 * empty. The whole list is read before anything is written, and the first
 * thing wrong with it is the usage error: a name that is no property, a
 * type there is none of, an enum with a case that is no constant's name, a
 * belongsTo naming none of the application's models.
 *
 * What a field is called becomes a property, a column, a form field and an
 * object key in TypeScript, and an enum's case a constant and its value a
 * string literal in both languages, so each is held to a shape with nothing
 * in it to escape.
 */
final class ResourceFields
{
    /** The most fields one resource takes: past this a form is several forms. */
    public const int MAX_FIELDS = 40;

    /** The most cases one enum field takes. */
    public const int MAX_CASES = 50;

    /**
     * The longest a field's name may be: past this, a name is a sentence,
     * and the views written for it no longer read as code.
     */
    public const int MAX_NAME = 40;

    /** What the generator writes on every resource itself, so no field may be called it. */
    private const array WRITTEN = ['id', 'createdAt', 'updatedAt'];

    /**
     * @param \Closure(string): (class-string<Model>|null) $findModel the application's model a
     *        name means, by its short name in any case or in full
     * @return non-empty-list<ResourceField>
     * @throws \InvalidArgumentException saying what is wrong with the first field that is
     */
    public static function parse(string $spec, \Closure $findModel): array
    {
        $spec = trim($spec);
        if ($spec === '') {
            throw new \InvalidArgumentException('A resource needs at least one field, as in --fields="title:string,price:int".');
        }

        $fields = [];
        foreach (self::split($spec) as $part) {
            $field = self::field($part, $findModel);
            if (isset($fields[$field->name])) {
                throw new \InvalidArgumentException("--fields names {$field->name} twice.");
            }
            $fields[$field->name] = $field;
        }
        if (\count($fields) > self::MAX_FIELDS) {
            throw new \InvalidArgumentException('--fields names ' . \count($fields) . ' fields; a resource takes at most ' . self::MAX_FIELDS . '.');
        }

        if ($fields === []) {
            throw new \InvalidArgumentException('A resource needs at least one field, as in --fields="title:string,price:int".');
        }

        // Two fields stored in one column would be a model the ORM refuses:
        // `customer:belongsTo` and `customerId:string` both want customer_id.
        $columns = [];
        foreach ($fields as $field) {
            $column = $field->column();
            if (isset($columns[$column])) {
                throw new \InvalidArgumentException("{$columns[$column]} and {$field->name} would both be stored in the column {$column}. Rename one.");
            }
            $columns[$column] = $field->name;
        }

        return array_values($fields);
    }

    /**
     * The fields of a list, split at the commas between them and not those
     * inside an enum's parentheses.
     *
     * @return list<string>
     */
    private static function split(string $spec): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($spec) as $character) {
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
                if ($depth < 0) {
                    throw new \InvalidArgumentException("--fields closes a parenthesis it never opened: '{$spec}'.");
                }
            }
            if ($character === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $character;
        }
        if ($depth !== 0) {
            throw new \InvalidArgumentException("--fields leaves a parenthesis open: '{$spec}'.");
        }
        $parts[] = trim($current);

        foreach ($parts as $part) {
            if ($part === '') {
                throw new \InvalidArgumentException("--fields has an empty field between two commas: '{$spec}'.");
            }
        }

        return $parts;
    }

    /** @param \Closure(string): (class-string<Model>|null) $findModel */
    private static function field(string $part, \Closure $findModel): ResourceField
    {
        if (preg_match('/^([^:]+):(\??)\s*([A-Za-z]+)\s*(?:\((.*)\))?\z/s', $part, $m) !== 1) {
            throw new \InvalidArgumentException(
                "'{$part}' is not a field: each is name:type, as in title:string, with '?' before the type for one that may be empty.",
            );
        }
        $name = Names::property(trim($m[1]));
        if (\strlen($name) > self::MAX_NAME) {
            throw new \InvalidArgumentException("{$name} is " . \strlen($name) . ' characters long; a field\'s name is at most ' . self::MAX_NAME . '.');
        }
        $nullable = $m[2] === '?';
        $kind = $m[3];
        $argument = isset($m[4]) ? trim($m[4]) : null;

        if (\in_array($name, self::WRITTEN, true)) {
            throw new \InvalidArgumentException("--fields names {$name}, which every resource has already: the id is the model's own, and createdAt and updatedAt are stamped by the ORM.");
        }
        // Redeclared on the model, a property the ORM keeps there is a
        // fatal error the first time the class loads.
        if (property_exists(Model::class, $name) || Names::isReserved($name)) {
            throw new \InvalidArgumentException("--fields names {$name}, which a model cannot have as a field: the ORM keeps its own state there, or the word is reserved.");
        }
        if (!\array_key_exists($kind, ResourceField::KINDS)) {
            throw new \InvalidArgumentException(
                "'{$kind}' is not a type a field may be. One of: " . implode(', ', array_keys(ResourceField::KINDS)) . ", as in {$name}:string.",
            );
        }
        if ($argument !== null && !\in_array($kind, ['enum', 'belongsTo'], true)) {
            throw new \InvalidArgumentException("{$name}:{$kind} takes nothing in parentheses; only enum(...) and belongsTo(Model) do.");
        }

        return match ($kind) {
            'enum' => new ResourceField($name, 'enum', $nullable, cases: self::cases($name, $argument ?? '')),
            'belongsTo' => self::belongsTo($name, $nullable, $argument, $findModel),
            default => new ResourceField($name, $kind, $nullable),
        };
    }

    /**
     * An enum's cases, each a constant's name in StudlyCase, stored as its
     * snake_case: Draft as 'draft', InReview as 'in_review'.
     *
     * @return array<string, string>
     */
    private static function cases(string $name, string $list): array
    {
        $cases = [];
        foreach ($list === '' ? [] : explode(',', $list) as $case) {
            $case = trim($case);
            if (preg_match('/^[A-Z][A-Za-z0-9]{0,63}\z/', $case) !== 1 || Names::isReserved($case) || \in_array(strtolower($case), ['cases', 'from', 'tryfrom'], true)) {
                throw new \InvalidArgumentException(
                    "'{$case}' is not a case {$name} may take: each is a capitalised name of letters and digits, as in {$name}:enum(Draft,Published).",
                );
            }
            $value = Str::snake($case);
            if (\in_array($value, $cases, true) || isset($cases[$case])) {
                throw new \InvalidArgumentException("{$name}'s cases name {$case} twice, or two cases that would be stored as '{$value}'.");
            }
            $cases[$case] = $value;
        }
        if ($cases === []) {
            throw new \InvalidArgumentException("{$name} is an enum of no values. Name them, as in {$name}:enum(Draft,Published).");
        }
        if (\count($cases) > self::MAX_CASES) {
            throw new \InvalidArgumentException("{$name} names " . \count($cases) . ' cases; an enum field takes at most ' . self::MAX_CASES . '.');
        }

        return $cases;
    }

    /** @param \Closure(string): (class-string<Model>|null) $findModel */
    private static function belongsTo(string $name, bool $nullable, ?string $argument, \Closure $findModel): ResourceField
    {
        // customerId would be stored in customer_id_id, and read as a raw id.
        if (preg_match('/(_id|Id)\z/', $name) === 1) {
            $stripped = (string) preg_replace('/(_id|Id)\z/', '', $name);
            throw new \InvalidArgumentException("{$name}:belongsTo names the relation by its key. Name it {$stripped}:belongsTo: the field is the row itself, its key {$stripped}_id.");
        }
        $named = $argument ?? $name;
        if ($named === '' || preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $named) !== 1) {
            throw new \InvalidArgumentException("belongsTo({$named}) names no model: give one of the application's models, as in {$name}:belongsTo(Customer).");
        }
        $model = $findModel($named) ?? throw new \InvalidArgumentException(
            "{$name}:belongsTo" . ($argument === null ? '' : "({$argument})") . " names '{$named}', which is not one of the application's models. "
            . 'Make it first (`tinvar make:model`), or name the model the field belongs to, as in ' . "{$name}:belongsTo(Customer).",
        );

        return new ResourceField($name, 'belongsTo', $nullable, model: $model);
    }
}
