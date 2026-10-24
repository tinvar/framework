<?php

declare(strict_types=1);

namespace Tinvar\Testing\Fakes;

use PHPUnit\Framework\Assert;
use Tinvar\Mail\Message;
use Tinvar\Mail\MailRenderer;
use Tinvar\Mail\RenderedMail;
use Tinvar\Mail\Transport;

/**
 * The mail fake: hand it to the Mailer as its transport and as its renderer,
 * and every email the application sends is kept -- the template, the props it
 * was drawn from, the message -- and none leaves.
 *
 * As the renderer it draws nothing unless given a real one to draw with: a
 * placeholder part each, with no links, so a test about what is sent, to
 * whom, from which template and props needs no Node. Given the application's
 * own renderer, it draws through it and keeps what was drawn, so a test can
 * read the real HTML too.
 *
 * Queued mail is the queue's until a worker sends it; QueueFake sees the job.
 */
final class MailFake implements Transport, MailRenderer
{
    /** @var list<SentMail> */
    private array $sent = [];

    /** @var list<array{template: string, props: array<string, mixed>}> drawn, and not yet sent */
    private array $drawn = [];

    public function __construct(private readonly ?MailRenderer $renderer = null) {}

    public function render(string $template, array $props): RenderedMail
    {
        $this->drawn[] = ['template' => $template, 'props' => $props];

        return $this->renderer?->render($template, $props) ?? new RenderedMail(
            "<p>{$template}</p>",
            $template,
        );
    }

    public function send(Message $message): void
    {
        // The Mailer draws, then sends, one email at a time: what was drawn
        // last is this message's template.
        $drawn = array_pop($this->drawn);
        $this->sent[] = new SentMail($drawn['template'] ?? '', $drawn['props'] ?? [], $message);
    }

    /**
     * What was sent from $template, or everything.
     *
     * @return list<SentMail>
     */
    public function sent(?string $template = null): array
    {
        return array_values(array_filter($this->sent, static fn (SentMail $mail): bool => $template === null || $mail->template === $template));
    }

    /**
     * An email from $template was sent -- one $check returns true for, given
     * one, which is handed each SentMail.
     */
    public function assertSent(string $template, ?\Closure $check = null): self
    {
        $sent = $this->sent($template);
        Assert::assertNotEmpty($sent, "No email from '{$template}' was sent." . $this->summary());
        if ($check !== null) {
            Assert::assertNotEmpty(array_filter($sent, static fn (SentMail $mail): bool => $check($mail) === true), "No email from '{$template}' matched the closure.");
        }

        return $this;
    }

    /** An email -- from $template, given one -- went to $address. */
    public function assertSentTo(string $address, ?string $template = null): self
    {
        Assert::assertNotEmpty(
            array_filter($this->sent($template), static fn (SentMail $mail): bool => $mail->isTo($address)),
            "No email" . ($template === null ? '' : " from '{$template}'") . " went to {$address}." . $this->summary(),
        );

        return $this;
    }

    public function assertNotSent(string $template): self
    {
        Assert::assertSame([], $this->sent($template), "An email from '{$template}' was sent.");

        return $this;
    }

    public function assertSentCount(int $count): self
    {
        Assert::assertCount($count, $this->sent, \count($this->sent) . " emails were sent, not {$count}." . $this->summary());

        return $this;
    }

    public function assertNothingSent(): self
    {
        return $this->assertSentCount(0);
    }

    private function summary(): string
    {
        if ($this->sent === []) {
            return ' Nothing was sent.';
        }

        return ' Sent: ' . implode(', ', array_map(static fn (SentMail $mail): string => "'{$mail->template}' to " . implode(' ', array_map(static fn (\Tinvar\Mail\Address $to): string => $to->email, $mail->message->to)), $this->sent)) . '.';
    }
}
