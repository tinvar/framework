<?php

declare(strict_types=1);

namespace Tinvar\Generators;

use Tinvar\Orm\Model;
use Tinvar\Support\Str;

/**
 * One field of a resource make:resource writes, as --fields or the prompts
 * named it: `title:string`, `status:enum(Draft,Published)`,
 * `customer:belongsTo`, `notes:?text`.
 *
 * Every part of it has been held to a shape by ResourceFields before it is
 * made -- the name a property, each case a class constant's name, the model
 * one of the application's -- so the sources can write each as it is.
 */
final readonly class ResourceField
{
    /** What a field may be, and what each is written as. */
    public const array KINDS = [
        'string' => 'a line of text, up to 255 characters: a string column',
        'text' => 'text of any length: a text column',
        'int' => 'a whole number: an integer column',
        'float' => 'a number with a fraction: a float column',
        'bool' => 'yes or no, no by default: a boolean column',
        'timestamp' => 'a moment, read as CarbonImmutable in UTC: a timestamp() column',
        'enum' => 'one of the values named after it, as enum(Draft,Published): a backed enum and an enum() column',
        'belongsTo' => "a row of another of the app's models, by the field's name or as belongsTo(Customer): a foreign(Model::class) key",
    ];

    /**
     * @param 'string'|'text'|'int'|'float'|'bool'|'timestamp'|'enum'|'belongsTo' $kind
     * @param array<string, string> $cases an enum's cases, name => the value stored
     * @param class-string<Model>|null $model what a belongsTo field takes
     */
    public function __construct(
        public string $name,
        public string $kind,
        public bool $nullable = false,
        public array $cases = [],
        public ?string $model = null,
    ) {}

    /** The column it is stored in: published_at for publishedAt, customer_id for customer. */
    public function column(): string
    {
        return Str::snake($this->name) . ($this->kind === 'belongsTo' ? '_id' : '');
    }

    /** How a person reads its name: Published at for publishedAt. */
    public function label(): string
    {
        return ucfirst(strtolower((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $this->name)));
    }

    /** As --fields would spell it, so an answer to the prompts is checked exactly as typed ones are. */
    public function spec(): string
    {
        $type = match ($this->kind) {
            'enum' => 'enum(' . implode(',', array_keys($this->cases)) . ')',
            'belongsTo' => 'belongsTo(' . $this->model . ')',
            default => $this->kind,
        };

        return "{$this->name}:" . ($this->nullable ? '?' : '') . $type;
    }
}
