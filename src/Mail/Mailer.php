<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Data\Encoder;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Observability\Span;
use Tinvar\Observability\Telemetry;

/**
 * Sends the application's email: renders a Mailable's template through the
 * renderer, and hands the message to the transport -- now, or on a worker.
 *
 * Queue it from a request: rendering is a round trip to the renderer and
 * sending one to an SMTP server, neither of which a visitor should wait
 * on, and a queued send is retried with backoff when the server is down.
 * Queued inside a transaction, it is sent only once the transaction
 * commits, so a rolled-back order sends no confirmation.
 *
 * Given a suppression list, it asks it before every send (ADR 0146): a
 * reader the list holds for a reason that blocks the message's category
 * (MailCategory) is left off, and a message with no To reader left is not
 * sent at all. What became of the send -- the provider's message id, the
 * transport that took it, who was left off -- is deliver()'s answer.
 *
 * Given a sent-mail log, it records each message id the provider hands
 * back against the message's readers, which is what a webhook's event is
 * checked against (SentMailLog). The message has gone by then: a log that
 * cannot be written does not fail the send -- that would send it again --
 * and the webhook's events for it are ignored instead.
 */
final readonly class Mailer
{
    public function __construct(
        private Transport $transport,
        private MailRenderer $renderer,
        private Address $from,
        private ?Dispatcher $jobs = null,
        private Encoder $encoder = new Encoder(),
        private ?Telemetry $telemetry = null,
        private ?Suppressions $suppressions = null,
        private ?SentMailLog $sentLog = null,
    ) {}

    /**
     * Renders and sends $mailable now, and returns what was sent: a span of
     * the request or job sending it, or a trace of its own (ADR 0112),
     * named by its template -- never its recipient or its subject.
     */
    public function send(Mailable $mailable): Message
    {
        return $this->deliver($mailable)->message;
    }

    /**
     * As send(), and says what became of it: the provider's message id, the
     * transport that took it, and the readers the suppression list left
     * off -- or that it was not sent at all, every To reader suppressed.
     */
    public function deliver(Mailable $mailable): SentMessage
    {
        $email = $mailable->email();
        if ($this->telemetry === null) {
            return $this->compose($email);
        }

        return $this->telemetry->trace("mail {$email->template}", ['tinvar.mail.template' => $email->template], function (?Span $span) use ($email): SentMessage {
            $sent = $this->compose($email);
            $span?->set('tinvar.mail.transport', $sent->transport)->set('tinvar.mail.suppressed', \count($sent->suppressed));

            return $sent;
        });
    }

    private function compose(Email $email): SentMessage
    {
        $props = $this->encoder->encode($email->props);
        if (!\is_array($props)) {
            throw new MailRenderingException("The props of '{$email->template}' did not encode as a map.");
        }

        /** @var array<string, mixed> $props */
        $rendered = $this->renderer->render($email->template, $props, $email->links);
        // Held again here, whatever drew it: the renderer is not trusted
        // with what reaches a reader, and what is sent is the document the
        // check read, written out again (ADR 0086).
        $html = SafeMailHtml::clean($rendered->html, $email->links);
        $message = new Message(
            $this->from,
            $email->to,
            $email->subject,
            $html,
            $rendered->text,
            $email->replyTo,
            $email->unsubscribe,
            $email->cc,
            $email->bcc,
            $email->attachments,
            $email->tags,
            $email->metadata,
        );

        [$kept, $suppressed] = $this->suppress($message, $email->category);
        if ($kept === null) {
            return new SentMessage($message, null, 'none', $suppressed, skipped: true);
        }

        $sent = SentMessage::through($this->transport, $kept)->withSuppressed($suppressed);
        try {
            $this->sentLog?->record($sent, time());
        } catch (\Throwable) {
            // Sent, and not logged: its bounces will be ignored, which is
            // better than the retry a throw here would cost -- the message
            // sent twice.
        }

        return $sent;
    }

    /**
     * The message without the readers the list blocks for its category --
     * null when no To reader is left -- and who was left off.
     *
     * @return array{?Message, list<string>}
     */
    private function suppress(Message $message, MailCategory $category): array
    {
        if ($this->suppressions === null || $category === MailCategory::Essential) {
            return [$message, []];
        }

        $blocked = [];
        foreach ($this->suppressions->find($message->envelope()) as $email => $suppression) {
            if ($suppression->reason->blocks($category)) {
                $blocked[strtolower($email)] = true;
            }
        }
        if ($blocked === []) {
            return [$message, []];
        }

        $left = array_values(array_filter($message->envelope(), static fn (string $email): bool => isset($blocked[strtolower($email)])));

        return [$message->keeping(static fn (Address $reader): bool => !isset($blocked[strtolower($reader->email)])), $left];
    }

    /**
     * Sends $mailable on a worker, after the commit if a transaction is open.
     *
     * @return string the job's id
     */
    public function queue(Mailable $mailable, int $delay = 0): string
    {
        if ($this->jobs === null) {
            throw new \LogicException('This mailer was given no job dispatcher to queue with.');
        }

        return $this->jobs->dispatch(new SendMail($mailable), $delay);
    }
}
