<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * Answers scripted for a test, and a record of what was asked and said:
 *
 *     $prompts = Prompts::fake(['name' => 'Rack', 'Make a migration?' => true]);
 *     $console->run(['tinvar', 'make:model']);
 *     $prompts->assertAsked('name')->assertAllAnswered();
 *
 * An answer is found by the prompt's name, then by its label; answers
 * given as a list are taken in order by prompts no key names. Each is held
 * to the prompt's rules as a person's would be (Prompt::accept()), so a
 * test cannot answer what a person could not. A prompt with no answer
 * scripted answers with its default, as with nobody there, or fails the
 * test.
 *
 * Notes, tables and finished progress bars are kept as plain lines.
 *
 * The assertions are PHPUnit's where it is loaded, so a test that makes
 * only these makes assertions; elsewhere a failed one throws.
 */
final class Fake
{
    /** @var array<string, mixed> */
    private array $named = [];

    /** @var list<mixed> */
    private array $queue = [];

    /** @var array<string, true> */
    private array $used = [];

    /** @var list<array{type: string, label: string, name: ?string, answer: mixed}> */
    private array $asked = [];

    /** @var list<string> */
    private array $output = [];

    /** @param array<int|string, mixed> $answers */
    public function __construct(array $answers = [])
    {
        foreach ($answers as $key => $answer) {
            if (\is_int($key)) {
                $this->queue[] = $answer;
            } else {
                $this->named[$key] = $answer;
            }
        }
    }

    /** The answer to $prompt: scripted, or its default. */
    public function answer(Prompt $prompt): mixed
    {
        $key = match (true) {
            $prompt->name !== null && \array_key_exists($prompt->name, $this->named) => $prompt->name,
            \array_key_exists($prompt->label, $this->named) => $prompt->label,
            default => null,
        };

        if ($key !== null) {
            $this->used[$key] = true;
            $answer = $prompt->accept($this->named[$key]);
        } elseif ($this->queue !== [] && !$prompt instanceof PausePrompt) {
            $answer = $prompt->accept(array_shift($this->queue));
        } else {
            try {
                $answer = $prompt->fallback();
            } catch (CannotPrompt $e) {
                throw new CannotPrompt(
                    "No answer was faked for '{$prompt->label}'" . ($prompt->name === null ? '' : " ({$prompt->name})") . ', and it has no default to answer with.',
                    0,
                    $e,
                );
            }
        }
        $this->asked[] = ['type' => $prompt->type(), 'label' => $prompt->label, 'name' => $prompt->name, 'answer' => $answer];

        return $answer;
    }

    /** Lines a note, table or progress bar would have drawn. */
    public function said(string ...$lines): void
    {
        foreach ($lines as $line) {
            $this->output[] = Width::strip($line);
        }
    }

    /**
     * Every prompt asked, in order, with what it was answered.
     *
     * @return list<array{type: string, label: string, name: ?string, answer: mixed}>
     */
    public function asked(): array
    {
        return $this->asked;
    }

    /** Everything said, a line each. */
    public function output(): string
    {
        return implode("\n", $this->output);
    }

    /** A prompt named, or labelled, $question was asked. */
    public function assertAsked(string $question): self
    {
        return $this->check($this->find($question) !== [], "Nothing asked '{$question}'. Asked: " . $this->list() . '.');
    }

    public function assertNotAsked(string $question): self
    {
        return $this->check($this->find($question) === [], "'{$question}' was asked, and should not have been.");
    }

    public function assertNothingAsked(): self
    {
        return $this->check($this->asked === [], 'Nothing should have been asked. Asked: ' . $this->list() . '.');
    }

    /** Every answer scripted was asked for. */
    public function assertAllAnswered(): self
    {
        $unused = array_keys(array_diff_key($this->named, $this->used));

        return $this->check(
            $unused === [] && $this->queue === [],
            'Answers were scripted and nothing asked for them: ' . implode(', ', [...$unused, ...array_map(static fn (mixed $answer): string => var_export($answer, true), $this->queue)]) . '.',
        );
    }

    public function assertOutputContains(string $text): self
    {
        return $this->check(str_contains($this->output(), $text), "What was said does not contain '{$text}':\n" . $this->output());
    }

    /** @return list<array{type: string, label: string, name: ?string, answer: mixed}> */
    private function find(string $question): array
    {
        return array_values(array_filter($this->asked, static fn (array $asked): bool => $asked['name'] === $question || $asked['label'] === $question));
    }

    private function list(): string
    {
        return $this->asked === [] ? 'nothing' : implode(', ', array_map(static fn (array $asked): string => "'{$asked['label']}'", $this->asked));
    }

    private function check(bool $passes, string $message): self
    {
        $assert = 'PHPUnit\\Framework\\Assert';
        if (class_exists($assert)) {
            $assert::assertTrue($passes, $message);
        } elseif (!$passes) {
            throw new \LogicException($message);
        }

        return $this;
    }
}
