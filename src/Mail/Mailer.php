<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Data\Encoder;
use Tinvar\Jobs\Dispatcher;

/**
 * Sends the application's email: renders a Mailable's template through the
 * renderer, and hands the message to the transport -- now, or on a worker.
 *
 * Queue it from a request: rendering is a round trip to the renderer and
 * sending one to an SMTP server, neither of which a visitor should wait
 * on, and a queued send is retried with backoff when the server is down.
 * Queued inside a transaction, it is sent only once the transaction
 * commits, so a rolled-back order sends no confirmation.
 */
final readonly class Mailer
{
    public function __construct(
        private Transport $transport,
        private MailRenderer $renderer,
        private Address $from,
        private ?Dispatcher $jobs = null,
        private Encoder $encoder = new Encoder(),
    ) {}

    /** Renders and sends $mailable now, and returns what was sent. */
    public function send(Mailable $mailable): Message
    {
        $email = $mailable->email();
        $props = $this->encoder->encode($email->props);
        if (!\is_array($props)) {
            throw new MailRenderingException("The props of '{$email->template}' did not encode as a map.");
        }

        /** @var array<string, mixed> $props */
        $rendered = $this->renderer->render($email->template, $props);
        $message = new Message($this->from, $email->to, $email->subject, $rendered->html, $rendered->text, $email->replyTo);
        $this->transport->send($message);

        return $message;
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
