<?php

declare(strict_types=1);

namespace Tinvar\Search;

use Tinvar\Console\Prompts;
use Tinvar\Console\Prompts\Cancelled;

/**
 * `tinvar search:reindex [--model=Product,...] [--tenant=ID] [--force]`
 * (ADR 0150): builds the index of each #[Searchable] model again from its
 * rows, under a progress bar.
 *
 * Which models: those --model names, by class or short name; where a
 * person is there and it names none, those they choose (every one, unless
 * they say otherwise); with nobody there, every one. Every tenant's rows,
 * each read as its own tenant, or the one --tenant names. A remote index
 * is emptied before it is filled, so searches find less until it is done:
 * a person is asked first, unless --force; with nobody there it goes ahead,
 * as the command was run to.
 */
final readonly class ReindexCommand
{
    public const string USAGE = 'search:reindex [--model=Product,...] [--tenant=ID] [--force]';

    public function __construct(private Search $search) {}

    /**
     * Run by the Console, which reads its options: --model's value,
     * --tenant's and whether --force was given.
     *
     * @param \Closure(string): void $line a line for whoever ran it
     * @param \Closure(string): void $error a failure, for whoever ran it
     */
    public function run(?string $model, ?string $tenant, bool $force, \Closure $line, \Closure $error): int
    {
        $models = $this->search->models();
        if ($models === []) {
            $error('No model is #[Searchable]: add #[Searchable(fields: [...])] to one, and run this again.');

            return 1;
        }

        $chosen = $this->chosen(array_keys($models), $model);
        if ($tenant === '') {
            throw new \InvalidArgumentException('--tenant names a tenant: --tenant=ID.');
        }

        if ($this->search->backend()->remote() && !$force && Prompts::canAsk()
            && !Prompts::confirm('Empty and rebuild the index of ' . \count($chosen) . ' model(s)? Searches find less until it is done.', name: 'reindex')) {
            throw new Cancelled('Nothing reindexed.');
        }

        $totals = [];
        foreach ($chosen as $class) {
            $totals[$class] = $this->search->rowCount($class, $models[$class]->tenantScoped() ? $tenant : null);
        }

        $bar = Prompts::progress('Reindexing', array_sum($totals))->start();
        $indexed = [];
        try {
            foreach ($chosen as $class) {
                $bar->hint(self::short($class));
                $indexed[$class] = $this->search->reindex(
                    $class,
                    $models[$class]->tenantScoped() ? $tenant : null,
                    static function (int $rows) use ($bar): void {
                        $bar->advance($rows);
                    },
                );
            }
        } catch (\Throwable $e) {
            $bar->release();
            throw $e;
        }
        $bar->finish();

        foreach ($indexed as $class => $rows) {
            $line("indexed {$rows} " . self::short($class) . ($rows === 1 ? ' row' : ' rows'));
        }

        return 0;
    }

    /**
     * The models to reindex: as --model names them, or as a person chooses.
     *
     * @param list<class-string> $all
     * @return list<class-string>
     */
    private function chosen(array $all, ?string $named): array
    {
        if ($named !== null) {
            $chosen = [];
            foreach (array_filter(array_map(trim(...), explode(',', $named)), static fn (string $n): bool => $n !== '') as $name) {
                $match = array_values(array_filter($all, static fn (string $class): bool => strcasecmp($class, ltrim($name, '\\')) === 0 || strcasecmp(self::short($class), $name) === 0));
                if (\count($match) !== 1) {
                    throw new \InvalidArgumentException(
                        "--model={$name} names " . ($match === [] ? 'no' : 'more than one') . ' #[Searchable] model: name one of ' . implode(', ', $all) . '.',
                    );
                }
                $chosen[] = $match[0];
            }
            if ($chosen === []) {
                throw new \InvalidArgumentException('--model names at least one #[Searchable] model.');
            }

            return array_values(array_unique($chosen));
        }

        if (\count($all) === 1 || !Prompts::canAsk()) {
            return $all;
        }

        $answer = Prompts::multiselect('Which models?', array_combine($all, array_map(self::short(...), $all)), default: $all, required: 'Choose at least one model.', name: 'models');

        return array_values(array_filter($all, static fn (string $class): bool => \in_array($class, $answer, true)));
    }

    private static function short(string $class): string
    {
        $at = strrpos($class, '\\');

        return $at === false ? $class : substr($class, $at + 1);
    }
}
