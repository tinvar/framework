<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * One of what a search finds: the closure is asked again as the query
 * changes -- once for each batch of keys read, so a paste or fast typing
 * is one search, not one a key -- and the arrows choose among what it
 * returned. Its results answer as a select's options do: a list with the
 * result, keyed with the key.
 *
 * There is no default to fall back on: with nobody there it is refused,
 * unless it is not required, when it answers null.
 */
final class SearchPrompt extends Prompt
{
    use EditsText;
    use ChoosesOptions;

    private ?string $searched = null;

    /**
     * @param \Closure(string): array<int|string, string> $search the results for a query
     * @param (\Closure(mixed): (string|bool|null))|null $validate
     */
    public function __construct(
        string $label,
        private readonly \Closure $search,
        public readonly string $placeholder = '',
        public readonly int $scroll = 5,
        bool|string $required = true,
        ?\Closure $validate = null,
        string $hint = '',
        ?string $name = null,
    ) {
        parent::__construct($label, $required, $validate, $hint, $name);
        $this->settle();
    }

    public function type(): string
    {
        return 'search';
    }

    public function value(): int|string|null
    {
        $option = $this->current();

        return $option === null ? null : $this->answerOf($option);
    }

    /** A faked answer is one the search returns for nothing typed, or for the answer itself. */
    public function set(mixed $answer): void
    {
        foreach (['', \is_scalar($answer) ? (string) $answer : ''] as $query) {
            $this->replace($query);
            $this->settle();
            $index = $this->indexOf($answer);
            if ($index !== null) {
                $this->highlightIndex($index);

                return;
            }
        }
        throw new \InvalidArgumentException(var_export($answer, true) . ' is not a result the search returns for it.');
    }

    public function blank(): bool
    {
        return $this->cursor === 0;
    }

    public function settle(): void
    {
        if ($this->searched === $this->value) {
            return;
        }
        $this->searched = $this->value;
        $this->load(($this->search)($this->value));
        $this->highlighted = 0;
        $this->first = 0;
    }

    protected function key(string $key): void
    {
        if ($key === Key::ENTER) {
            if ($this->current() === null) {
                $this->state = self::ERROR;
                $this->error = 'Nothing found to choose.';

                return;
            }
            $this->submit();

            return;
        }
        $this->navigate($key) || $this->edit($key);
    }

    protected function body(Style $style, int $width): array
    {
        return [
            $this->field($style, $width, placeholder: $this->placeholder),
            ...$this->rows($style, $width, static fn (array $option, bool $on): string => $on ? $style->cyan('› ') : '  ', 'Nothing found.'),
        ];
    }

    protected function info(): string
    {
        return \count($this->matches) > $this->visible() ? (($this->highlighted ?? 0) + 1) . '/' . \count($this->matches) : '';
    }

    protected function answered(): string
    {
        return $this->current()['label'] ?? '';
    }

    protected function preset(): mixed
    {
        return null;
    }
}
