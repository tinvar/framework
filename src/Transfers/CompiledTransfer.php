<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Panel;
use Tinvar\Data\Data;
use Tinvar\Orm\Model;
use Tinvar\Validation\FormRequest;

/** One #[Export] or #[Import] class, reflected once. */
final readonly class CompiledTransfer
{
    public const string EXPORT = 'export';
    public const string IMPORT = 'import';

    /**
     * @param self::EXPORT|self::IMPORT $kind
     * @param class-string $class
     * @param class-string<Data>|class-string<FormRequest> $row an export's row Data class, or an
     *        import's row form request
     * @param string $rowParameter an import's form-request parameter of __invoke; '' for an export
     * @param non-empty-list<string> $columns the file's columns, in the order an export writes them
     * @param list<string> $formats an export's formats, its default first; ['csv'] for an import
     * @param int $maxBytes an import's largest file; 0 for an export
     * @param list<Authorize> $checks the class's, then __invoke's
     * @param int $classLevelChecks how many of $checks, at the front, the class declared
     * @param array<string, class-string<Model>> $models start arguments that are models
     * @param array<string, true> $optionalModels those of them that may be left out
     * @param array<string, \ReflectionParameter> $scalars start arguments that are not
     * @param array<string, class-string<Model>> $rowModels an import row's model-typed fields
     * @param list<class-string<\Tinvar\Http\Middleware>> $middleware
     * @param list<Panel> $panels
     * @param bool $mapsRows an export declares row(), which maps each model to its row
     * @param list<string> $revalidates an import's tags, invalidated when its rows are applied (ADR 0075)
     */
    public function __construct(
        public string $kind,
        public string $name,
        public string $class,
        public string $row,
        public string $rowParameter,
        public array $columns,
        public array $formats,
        public int $maxRows,
        public int $maxBytes,
        public array $checks,
        public int $classLevelChecks,
        public array $models,
        public array $optionalModels,
        public array $scalars,
        public array $rowModels,
        public array $middleware,
        public ?string $tenantFrom,
        public array $panels,
        public bool $mapsRows = false,
        public array $revalidates = [],
    ) {}

    public function isExport(): bool
    {
        return $this->kind === self::EXPORT;
    }

    public function where(): string
    {
        return "{$this->kind} '{$this->name}' ({$this->class})";
    }

    /**
     * The checks a start, and the job that runs it, ask: every one but those
     * naming a row's field, which are asked of each row.
     *
     * @return list<Authorize>
     */
    public function startChecks(): array
    {
        return array_values(array_filter(
            $this->checks,
            fn (Authorize $check): bool => $check->argument === null || !isset($this->rowModels[$check->argument]),
        ));
    }

    /**
     * The checks asked of each row of an import: those naming one of its
     * model-typed fields.
     *
     * @return list<Authorize>
     */
    public function rowChecks(): array
    {
        return array_values(array_filter(
            $this->checks,
            fn (Authorize $check): bool => $check->argument !== null && isset($this->rowModels[$check->argument]),
        ));
    }

    /** How many of startChecks(), at the front, the class declared. */
    public function classLevelStartChecks(): int
    {
        return \count(array_filter(
            \array_slice($this->checks, 0, $this->classLevelChecks),
            fn (Authorize $check): bool => $check->argument === null || !isset($this->rowModels[$check->argument]),
        ));
    }
}
