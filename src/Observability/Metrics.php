<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * Counters and histograms, kept in a MetricsStore, and served at /_metrics
 * in Prometheus's text format -- the spec's "Prometheus" endpoint.
 *
 * A worker counts in memory as requests run, and adds what it counted to
 * the store when it flushes: after a response has gone (Telemetry's
 * terminate()), at most once every `flushEvery` seconds, and once more when
 * the process ends. So a request pays for array arithmetic, never for the
 * store; a scrape reads the store, up to `flushEvery` seconds behind each
 * worker.
 *
 * A process may read more stores than it writes to. The server's workers
 * count in APCu, which no other process can reach; the queue worker and
 * the scheduler, processes of their own, count into a file every process
 * opens (SqliteMetricsStore). The server reads that file too, `readAlso`,
 * and a scrape adds the two: a job counted on the worker is in the
 * server's /_metrics a flush later.
 *
 * Every metric is declared before it is used, with its labels: a label
 * nobody declared is a bug, and so is one left out. A label's values are
 * the caller's to keep few -- a route's name, never its path -- and as a
 * guard, a metric past MAX_SERIES label sets in one worker files the rest
 * under `_other` rather than growing without end. The count is this
 * instance's, and an instance is a worker's own -- one per thread under
 * FrankenPHP -- so a process of N workers may store up to N times that.
 */
final class Metrics
{
    /** Prometheus's default buckets, in seconds. */
    public const array SECONDS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    /** Label sets one metric may reach in one worker (this instance) before the rest are filed as `_other`. */
    public const int MAX_SERIES = 500;

    /** The text format's media type. */
    public const string CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    /** Values are kept in millionths, as integers, so a store needs only addition. */
    private const int SCALE = 1_000_000;

    private const string SEPARATOR = "\x1f";

    /** @var array<string, array{type: 'counter'|'histogram', help: string, labels: list<string>, buckets: list<float>}> */
    private array $definitions = [];

    /** @var array<string, int> series key => millionths not yet in the store */
    private array $pending = [];

    /** @var array<string, array<string, true>> metric => label sets seen by this worker */
    private array $series = [];

    /** @var list<Collector> */
    private array $collectors = [];

    private float $flushedAt = 0.0;

    private bool $flushesAtShutdown = false;

    /** @var (\Closure(\Throwable): void)|null */
    private ?\Closure $report = null;

    /**
     * @param float $flushEvery seconds between flushes to the store; 0 flushes after
     *        every request, which suits APCu, and a second suits a file
     * @param list<MetricsStore> $readAlso stores other processes count into,
     *        added to this one's at every scrape and never written to here
     */
    public function __construct(
        private readonly MetricsStore $store = new ArrayMetricsStore(),
        private readonly float $flushEvery = 0.0,
        private readonly array $readAlso = [],
    ) {}

    /** @param list<string> $labels */
    public function counter(string $name, string $help, array $labels = []): self
    {
        return $this->define($name, 'counter', $help, $labels, []);
    }

    /**
     * @param list<string> $labels
     * @param list<float> $buckets upper bounds, ascending; +Inf is added
     */
    public function histogram(string $name, string $help, array $labels = [], array $buckets = self::SECONDS): self
    {
        if ($buckets === [] || \in_array('le', $labels, true)) {
            throw new \InvalidArgumentException("Histogram {$name} needs buckets, and cannot have a label named 'le'.");
        }
        $sorted = $buckets;
        sort($sorted);
        if ($sorted !== $buckets || \count(array_unique($buckets)) !== \count($buckets) || array_filter($buckets, is_infinite(...)) !== []) {
            throw new \InvalidArgumentException("Histogram {$name}'s buckets must be finite, distinct and ascending.");
        }

        return $this->define($name, 'histogram', $help, $labels, array_map(floatval(...), $buckets));
    }

    /** Reads its gauges at every scrape. */
    public function collect(Collector $collector): self
    {
        $this->collectors[] = $collector;

        return $this;
    }

    /**
     * Where a store or a collector that fails is reported: Telemetry's log.
     * Neither ever fails the request or the scrape around it.
     *
     * @param \Closure(\Throwable): void $report
     */
    public function reportTo(\Closure $report): void
    {
        $this->report = $report;
    }

    /** @param array<string, string|int> $labels */
    public function increment(string $name, array $labels = [], float $by = 1.0): void
    {
        $definition = $this->definition($name, 'counter');
        if ($by < 0 || !is_finite($by)) {
            throw new \InvalidArgumentException("Counter {$name} only goes up.");
        }

        $key = $this->key($name, '', $this->labels($name, $definition['labels'], $labels), '');
        $this->pending[$key] = ($this->pending[$key] ?? 0) + (int) round($by * self::SCALE);
    }

    /** @param array<string, string|int> $labels */
    public function observe(string $name, float $value, array $labels = []): void
    {
        $definition = $this->definition($name, 'histogram');
        if (!is_finite($value)) {
            return;
        }

        $set = $this->labels($name, $definition['labels'], $labels);
        foreach ($definition['buckets'] as $le) {
            if ($value <= $le) {
                $key = $this->key($name, 'bucket', $set, self::number($le));
                $this->pending[$key] = ($this->pending[$key] ?? 0) + self::SCALE;
            }
        }
        foreach ([$this->key($name, 'bucket', $set, '+Inf'), $this->key($name, 'count', $set, '')] as $key) {
            $this->pending[$key] = ($this->pending[$key] ?? 0) + self::SCALE;
        }
        $sum = $this->key($name, 'sum', $set, '');
        $this->pending[$sum] = ($this->pending[$sum] ?? 0) + (int) round($value * self::SCALE);
    }

    /**
     * Adds what this process counted to the store -- when `flushEvery` has
     * passed since the last attempt, or now when forced. A store that fails
     * keeps the counts for the next flush, a `flushEvery` later, and is
     * reported.
     */
    public function flush(bool $force = false): void
    {
        if ($this->pending === []) {
            return;
        }

        if (!$force && $this->flushEvery > 0 && microtime(true) - $this->flushedAt < $this->flushEvery) {
            // Whatever is still held when the process ends -- a recycled
            // worker, the end of a php -S request -- goes then.
            if (!$this->flushesAtShutdown) {
                $this->flushesAtShutdown = true;
                register_shutdown_function(function (): void {
                    $this->flush(true);
                });
            }

            return;
        }

        // The attempt starts the interval, whether or not the store takes
        // the counts: a store that fails -- SQLite busy for its whole
        // timeout, APCu full -- is tried, and reported, at most once every
        // `flushEvery`, never after every request.
        $this->flushedAt = microtime(true);

        try {
            $this->store->add($this->pending);
            $this->pending = [];
        } catch (MetricsNotStored $e) {
            // Counted in part: only the rest is the next flush's, or what
            // the store took would be counted twice.
            $this->pending = $e->unwritten;
            $this->fail($e);
        } catch (\Throwable $e) {
            $this->fail($e);
        }
    }

    /** Every metric and every collector's gauges, in Prometheus's text format. */
    public function exposition(): string
    {
        $this->flush(true);

        // Each store on its own: one that fails is reported, and the rest
        // are still written.
        $stored = [];
        foreach ([$this->store, ...$this->readAlso] as $store) {
            try {
                $values = $store->all();
            } catch (\Throwable $e) {
                $this->fail($e);
                continue;
            }
            foreach ($values as $key => $value) {
                $stored[$key] = ($stored[$key] ?? 0) + $value;
            }
        }

        /** @var array<string, array<string, array<string, int>>> $byMetric metric => label set => suffix/le => value */
        $byMetric = [];
        foreach ($stored as $key => $value) {
            $parts = explode(self::SEPARATOR, $key);
            if (\count($parts) !== 4 || !isset($this->definitions[$parts[0]])) {
                continue;
            }
            [$name, $suffix, $set, $le] = $parts;
            // A store another process writes is read, not trusted: only a
            // key of the shape this class writes is written out.
            if (!$this->wellFormed($this->definitions[$name], $suffix, $set, $le)) {
                continue;
            }
            $byMetric[$name][$set][$suffix . self::SEPARATOR . $le] = $value;
        }

        $lines = [];
        foreach ($this->definitions as $name => $definition) {
            $lines[] = "# HELP {$name} " . self::help($definition['help']);
            $lines[] = "# TYPE {$name} {$definition['type']}";
            $sets = $byMetric[$name] ?? [];
            ksort($sets);

            foreach ($sets as $set => $values) {
                if ($definition['type'] === 'counter') {
                    $lines[] = $name . self::braces($set) . ' ' . self::scaled($values[self::SEPARATOR] ?? 0);
                    continue;
                }

                foreach ([...array_map(self::number(...), $definition['buckets']), '+Inf'] as $le) {
                    $lines[] = "{$name}_bucket" . self::braces($set === '' ? "le=\"{$le}\"" : "{$set},le=\"{$le}\"")
                        . ' ' . self::scaled($values['bucket' . self::SEPARATOR . $le] ?? 0);
                }
                $lines[] = "{$name}_sum" . self::braces($set) . ' ' . self::scaled($values['sum' . self::SEPARATOR] ?? 0);
                $lines[] = "{$name}_count" . self::braces($set) . ' ' . self::scaled($values['count' . self::SEPARATOR] ?? 0);
            }
        }

        return implode("\n", [...$lines, ...$this->collected()]) . "\n";
    }

    /**
     * Whether a series key read from a store is one this class would have
     * written for the metric: its suffix and bucket bound of its type, and a
     * label set of exactly its labels, in order, each value escaped as
     * labels() escapes one and holding no control character -- so nothing
     * read can end a line, or open a series of its own, in the text format.
     *
     * @param array{type: 'counter'|'histogram', help: string, labels: list<string>, buckets: list<float>} $definition
     */
    private function wellFormed(array $definition, string $suffix, string $set, string $le): bool
    {
        if ($definition['type'] === 'counter') {
            if ($suffix !== '' || $le !== '') {
                return false;
            }
        } elseif ($suffix === 'bucket') {
            if (!\in_array($le, [...array_map(self::number(...), $definition['buckets']), '+Inf'], true)) {
                return false;
            }
        } elseif (!\in_array($suffix, ['sum', 'count'], true) || $le !== '') {
            return false;
        }

        if ($set === '') {
            return $definition['labels'] === [];
        }
        if (preg_match_all('/\G(?:^|,)([a-zA-Z_][a-zA-Z0-9_]*)="((?:[^"\\\\\x00-\x1f\x7f]|\\\\[\\\\"n])*)"/', $set, $matches) === false) {
            return false;
        }

        // Matched from the start, each pair right after the last: whole only
        // when the pairs are all there is.
        return implode('', $matches[0]) === $set && $matches[1] === $definition['labels'];
    }

    /** @return list<string> */
    private function collected(): array
    {
        /** @var array<string, array{help: string, type: string, samples: list<string>}> $gauges */
        $gauges = [];
        foreach ($this->collectors as $collector) {
            try {
                $samples = $collector->collect();
            } catch (\Throwable $e) {
                $this->fail($e);
                continue;
            }

            foreach ($samples as $sample) {
                $name = $sample['name'];
                // A gauge named like a counter or histogram here would make
                // two families of one name, which a scraper refuses whole.
                if (!self::validName($name) || isset($this->definitions[$name])) {
                    continue;
                }
                $labels = $sample['labels'] ?? [];
                $set = [];
                foreach ($labels as $label => $value) {
                    if (self::validLabel($label)) {
                        $set[] = $label . '="' . self::escape(self::clean((string) $value)) . '"';
                    }
                }
                $gauges[$name] ??= ['help' => $sample['help'], 'type' => ($sample['type'] ?? 'gauge') === 'counter' ? 'counter' : 'gauge', 'samples' => []];
                $gauges[$name]['samples'][] = $name . self::braces(implode(',', $set)) . ' ' . self::number((float) $sample['value']);
            }
        }

        $lines = [];
        foreach ($gauges as $name => $gauge) {
            $lines[] = "# HELP {$name} " . self::help($gauge['help']);
            $lines[] = "# TYPE {$name} {$gauge['type']}";
            array_push($lines, ...$gauge['samples']);
        }

        return $lines;
    }

    /**
     * @param 'counter'|'histogram' $type
     * @param list<string> $labels
     * @param list<float> $buckets
     */
    private function define(string $name, string $type, string $help, array $labels, array $buckets): self
    {
        if (!self::validName($name)) {
            throw new \InvalidArgumentException("'{$name}' is not a metric name.");
        }
        foreach ($labels as $label) {
            if (!self::validLabel($label)) {
                throw new \InvalidArgumentException("'{$label}' is not a label name.");
            }
        }

        $definition = ['type' => $type, 'help' => $help, 'labels' => $labels, 'buckets' => $buckets];
        if (isset($this->definitions[$name]) && $this->definitions[$name] !== $definition) {
            throw new \LogicException("Metric {$name} is already defined differently.");
        }
        $this->definitions[$name] = $definition;

        return $this;
    }

    /**
     * @param 'counter'|'histogram' $type
     * @return array{type: 'counter'|'histogram', help: string, labels: list<string>, buckets: list<float>}
     */
    private function definition(string $name, string $type): array
    {
        $definition = $this->definitions[$name] ?? null;
        if ($definition === null || $definition['type'] !== $type) {
            throw new \LogicException("No {$type} named {$name} is defined.");
        }

        return $definition;
    }

    /**
     * The label set as the text format writes it, in the order declared.
     *
     * @param list<string> $declared
     * @param array<string, string|int> $given
     */
    private function labels(string $name, array $declared, array $given): string
    {
        if (\count($given) !== \count($declared) || array_diff($declared, array_keys($given)) !== []) {
            throw new \LogicException("{$name} takes the labels [" . implode(', ', $declared) . '], and was given [' . implode(', ', array_keys($given)) . '].');
        }

        $render = static function (bool $other) use ($declared, $given): string {
            $pairs = [];
            foreach ($declared as $label) {
                $pairs[] = $label . '="' . ($other ? '_other' : self::escape(self::clean((string) $given[$label]))) . '"';
            }

            return implode(',', $pairs);
        };

        $set = $render(false);
        if (!isset($this->series[$name][$set])) {
            if (\count($this->series[$name] ?? []) >= self::MAX_SERIES) {
                $set = $render(true);
            }
            $this->series[$name][$set] = true;
        }

        return $set;
    }

    private function key(string $name, string $suffix, string $set, string $le): string
    {
        return $name . self::SEPARATOR . $suffix . self::SEPARATOR . $set . self::SEPARATOR . $le;
    }

    private function fail(\Throwable $e): void
    {
        if ($this->report !== null) {
            ($this->report)($e);
        }
    }

    private static function validName(string $name): bool
    {
        return preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*\z/', $name) === 1;
    }

    private static function validLabel(string $label): bool
    {
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\z/', $label) === 1 && !str_starts_with($label, '__');
    }

    /** A label value: no control characters, which also keeps the store's separator out, and 128 bytes at most. */
    private static function clean(string $value): string
    {
        return substr((string) preg_replace('/[\x00-\x1f\x7f]/', '?', $value), 0, 128);
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    private static function help(string $help): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $help);
    }

    private static function braces(string $set): string
    {
        return $set === '' ? '' : '{' . $set . '}';
    }

    private static function scaled(int $millionths): string
    {
        return self::number($millionths / self::SCALE);
    }

    private static function number(float $value): string
    {
        if (floor($value) === $value && abs($value) < 1e15) {
            return \sprintf('%.0F', $value);
        }

        return rtrim(rtrim(\sprintf('%.9F', $value), '0'), '.');
    }
}
