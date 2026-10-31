<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * Any of several options: space ticks the highlighted one, ctrl-a ticks
 * every one in view of the filter (or unticks them, if all are), typing
 * filters, enter answers with the ticked ones in their options' order.
 * `required` means at least one.
 */
final class MultiSelectPrompt extends Prompt
{
    use ChoosesOptions;

    /** @var array<int, true> the ticked options, by index */
    private array $ticked = [];

    /**
     * @param array<int|string, string|int|float|\Stringable> $options
     * @param list<int|string> $default
     * @param (\Closure(mixed): (string|bool|null))|null $validate
     */
    public function __construct(
        string $label,
        array $options,
        public readonly array $default = [],
        public readonly int $scroll = 5,
        bool|string $required = false,
        ?\Closure $validate = null,
        string $hint = '',
        ?string $name = null,
    ) {
        parent::__construct($label, $required, $validate, $hint, $name);
        if ($options === []) {
            throw new \InvalidArgumentException("'{$label}' has no options to choose from.");
        }
        $this->load($options);
        try {
            $this->set($default);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException("The default for '{$label}' names {$e->getMessage()}", 0, $e);
        }
    }

    public function type(): string
    {
        return 'multiselect';
    }

    /** @return list<int|string> */
    public function value(): array
    {
        $chosen = [];
        foreach ($this->options as $index => $option) {
            if (isset($this->ticked[$index])) {
                $chosen[] = $this->answerOf($option);
            }
        }

        return $chosen;
    }

    public function set(mixed $answer): void
    {
        if (!\is_array($answer)) {
            throw new \InvalidArgumentException('a list of options, given ' . get_debug_type($answer) . '.');
        }
        $ticked = [];
        foreach ($answer as $one) {
            $index = $this->indexOf($one) ?? throw new \InvalidArgumentException(var_export($one, true) . ', which is not one of ' . $this->choices() . '.');
            $ticked[$index] = true;
        }
        $this->ticked = $ticked;
    }

    public function blank(): bool
    {
        return $this->query === '';
    }

    protected function key(string $key): void
    {
        $option = $this->current();
        if ($key === Key::ENTER) {
            $this->submit();
        } elseif ($key === Key::SPACE) {
            if ($option !== null) {
                $index = $this->matches[$this->highlighted ?? 0];
                if (isset($this->ticked[$index])) {
                    unset($this->ticked[$index]);
                } else {
                    $this->ticked[$index] = true;
                }
            }
        } elseif ($key === Key::CTRL_A) {
            $all = array_all($this->matches, fn (int $index): bool => isset($this->ticked[$index]));
            foreach ($this->matches as $index) {
                if ($all) {
                    unset($this->ticked[$index]);
                } else {
                    $this->ticked[$index] = true;
                }
            }
        } else {
            $this->navigate($key) || $this->typeFilter($key, spaceFilters: false);
        }
    }

    protected function body(Style $style, int $width): array
    {
        $rows = $this->rows($style, $width, function (array $option, bool $on) use ($style): string {
            $index = array_search($option, $this->options, true);
            $box = isset($this->ticked[$index]) ? $style->cyan('◼') : $style->dim('◻');

            return ($on ? $style->cyan('› ') : '  ') . $box . ' ';
        });

        return $this->query === '' ? $rows : [$style->gray('⌕ ') . $this->query, ...$rows];
    }

    protected function info(): string
    {
        return \count($this->ticked) . ' selected';
    }

    protected function footer(): string
    {
        return 'space to tick · ' . ($this->query === '' ? 'type to filter' : 'backspace to widen') . ($this->mayGoBack ? ' · shift+tab back' : '');
    }

    protected function answered(): string
    {
        $labels = [];
        foreach ($this->options as $index => $option) {
            if (isset($this->ticked[$index])) {
                $labels[] = $option['label'];
            }
        }

        return $labels === [] ? 'None' : implode(', ', $labels);
    }

    protected function preset(): mixed
    {
        return $this->value();
    }
}
