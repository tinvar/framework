<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

use Tinvar\Console\Prompts;

/**
 * Several prompts, asked in turn, that a person can step back through:
 * shift-tab -- or ctrl-u with nothing typed to clear -- at any step past
 * the first asks the one before again, with its answer as it was left.
 *
 *     $answers = Prompts::form()
 *         ->text('What is the model called?', required: true, name: 'name')
 *         ->confirm('Make a factory?', default: false, name: 'factory')
 *         ->add(fn (array $answers) => Prompts::text('Table', default: strtolower($answers['name']) . 's'), name: 'table')
 *         ->submit();
 *
 * Each method takes what the Prompts method of its name takes; `name` keys
 * the answer, which is otherwise keyed by its step's place. add() takes any
 * closure, handed the answers so far: one that asks nothing is passed over
 * on the way back. Ctrl-c cancels the whole form.
 */
final class Form
{
    /** @var list<array{name: int|string|null, step: \Closure(array<int|string, mixed>): mixed}> */
    private array $steps = [];

    /** @param \Closure(array<int|string, mixed>): mixed $step handed the answers so far */
    public function add(\Closure $step, ?string $name = null): self
    {
        $this->steps[] = ['name' => $name, 'step' => $step];

        return $this;
    }

    public function text(string $label, mixed ...$options): self
    {
        return $this->step('text', $label, $options);
    }

    public function textarea(string $label, mixed ...$options): self
    {
        return $this->step('textarea', $label, $options);
    }

    public function password(string $label, mixed ...$options): self
    {
        return $this->step('password', $label, $options);
    }

    public function confirm(string $label, mixed ...$options): self
    {
        return $this->step('confirm', $label, $options);
    }

    /** @param array<int|string, string> $options */
    public function select(string $label, array $options, mixed ...$more): self
    {
        return $this->step('select', $label, ['options' => $options, ...$more]);
    }

    /** @param array<int|string, string> $options */
    public function multiselect(string $label, array $options, mixed ...$more): self
    {
        return $this->step('multiselect', $label, ['options' => $options, ...$more]);
    }

    /** @param list<string>|(\Closure(string): array<int|string, string>) $options */
    public function suggest(string $label, array|\Closure $options, mixed ...$more): self
    {
        return $this->step('suggest', $label, ['options' => $options, ...$more]);
    }

    /** @param \Closure(string): array<int|string, string> $options */
    public function search(string $label, \Closure $options, mixed ...$more): self
    {
        return $this->step('search', $label, ['options' => $options, ...$more]);
    }

    public function pause(string $message = 'Press enter to continue.', ?string $name = null): self
    {
        return $this->add(static fn (): bool => Prompts::pause($message, name: $name), $name);
    }

    /**
     * Asks each step, and every answer, keyed by name or place.
     *
     * @return array<int|string, mixed>
     */
    public function submit(): array
    {
        $answers = [];
        /** @var array<int, bool> $prompted */
        $prompted = [];
        /** @var array<int, int> $drawn the lines each step's answers left on screen */
        $drawn = [];
        $keys = [];
        foreach ($this->steps as $i => $step) {
            $keys[$i] = $step['name'] ?? $i;
        }

        $i = 0;
        $count = \count($this->steps);
        while ($i < $count) {
            $key = $keys[$i];
            $before = [];
            for ($j = 0; $j < $i; ++$j) {
                $before[$keys[$j]] = $answers[$keys[$j]] ?? null;
            }
            try {
                [$answer, $asked, $lines] = Prompts::step(
                    fn (): mixed => ($this->steps[$i]['step'])($before),
                    \array_key_exists($key, $answers),
                    $answers[$key] ?? null,
                    mayGoBack: array_any(\array_slice($prompted, 0, $i), static fn (bool $was): bool => $was),
                );
                $answers[$key] = $answer;
                $prompted[$i] = $asked > 0;
                $drawn[$i] = $lines;
                ++$i;
            } catch (GoBack) {
                // Back to the last step that asked something: one that
                // asked nothing would only send the person forward again.
                // Its answer, and any passed over, come off the screen.
                $erase = 0;
                do {
                    --$i;
                    $erase += $drawn[$i] ?? 0;
                } while ($i > 0 && !($prompted[$i] ?? false));
                Prompts::unDraw($erase);
            }
        }

        $ordered = [];
        foreach ($keys as $key) {
            $ordered[$key] = $answers[$key];
        }

        return $ordered;
    }

    /** @param array<int|string, mixed> $options */
    private function step(string $type, string $label, array $options): self
    {
        $name = $options['name'] ?? null;
        if ($name !== null && !\is_string($name)) {
            throw new \InvalidArgumentException("A form step's name is a string; '{$label}' was given " . get_debug_type($name) . '.');
        }

        return $this->add(static fn (): mixed => Prompts::{$type}($label, ...$options), $name);
    }
}
