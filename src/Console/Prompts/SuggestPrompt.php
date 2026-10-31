<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * Text, with suggestions under it as it is typed: the options containing
 * it, or what a closure returns for it. Up and down highlight one, tab
 * completes the line with it (the first, if none is highlighted), enter
 * answers with the highlighted one or with what is typed -- which need not
 * be a suggestion.
 */
final class SuggestPrompt extends Prompt
{
    use EditsText;
    use ChoosesOptions;

    /** @var (\Closure(string): array<int|string, string>)|null */
    private readonly ?\Closure $suggest;

    private ?string $suggested = null;

    /**
     * @param list<string>|(\Closure(string): array<int|string, string>) $options
     * @param (\Closure(mixed): (string|bool|null))|null $validate
     */
    public function __construct(
        string $label,
        array|\Closure $options,
        public readonly string $placeholder = '',
        public readonly string $default = '',
        public readonly int $scroll = 5,
        bool|string $required = false,
        ?\Closure $validate = null,
        string $hint = '',
        ?string $name = null,
    ) {
        parent::__construct($label, $required, $validate, $hint, $name);
        $this->suggest = $options instanceof \Closure ? $options : null;
        $this->highlighted = null;
        $this->replace($default);
        $this->load($options instanceof \Closure ? [] : $options);
        $this->settle();
    }

    public function type(): string
    {
        return 'suggest';
    }

    public function value(): string
    {
        return $this->value;
    }

    public function set(mixed $answer): void
    {
        if (!\is_scalar($answer) && $answer !== null) {
            throw new \InvalidArgumentException('a line of text, given ' . get_debug_type($answer) . '.');
        }
        $this->replace((string) $answer);
        $this->settle();
    }

    public function blank(): bool
    {
        return $this->cursor === 0;
    }

    public function settle(): void
    {
        if ($this->suggest !== null) {
            if ($this->suggested === $this->value) {
                return;
            }
            $this->suggested = $this->value;
            $highlighted = $this->highlighted;
            $this->load(array_values(($this->suggest)($this->value)));
            $this->highlighted = $highlighted;
            $this->query = '';
        } else {
            $this->query = $this->value;
        }
        $this->filter();
    }

    protected function key(string $key): void
    {
        switch ($key) {
            case Key::ENTER:
                $option = $this->current();
                if ($option !== null) {
                    $this->replace($option['label']);
                }
                $this->highlighted = null;
                $this->submit();

                return;
            case Key::TAB:
                $option = $this->current() ?? ($this->matches === [] ? null : $this->options[$this->matches[0]]);
                if ($option !== null) {
                    $this->replace($option['label']);
                }
                $this->highlighted = null;

                return;
            case Key::UP:
            case Key::DOWN:
                $this->navigate($key);

                return;
        }
        if ($this->edit($key)) {
            $this->highlighted = null;
            $this->first = 0;
        }
    }

    protected function body(Style $style, int $width): array
    {
        $lines = [$this->field($style, $width, placeholder: $this->placeholder)];
        if ($this->matches !== [] && !($this->highlighted === null && \count($this->matches) === 1 && $this->options[$this->matches[0]]['label'] === $this->value)) {
            $lines = [...$lines, ...$this->rows($style, $width, static fn (array $option, bool $on): string => $on ? $style->cyan('› ') : '  ')];
        }

        return $lines;
    }

    protected function footer(): string
    {
        return ($this->matches === [] ? '' : 'tab to complete') . ($this->mayGoBack ? ($this->matches === [] ? '' : ' · ') . 'shift+tab back' : '');
    }

    protected function answered(): string
    {
        return $this->value;
    }

    protected function preset(): mixed
    {
        return $this->default;
    }
}
