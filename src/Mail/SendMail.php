<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Attributes\Queue;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\JobRefused;

/**
 * A queued send: the mailable travels as data, and the email is built and
 * rendered on the worker.
 *
 * On its own queue, so a backlog of mail does not hold up other work, and
 * tried for a while before it fails: a mail server that is down is usually
 * back within the hour. Only what may get through later is tried again: a
 * refusal of the message itself -- a provider's 422, an SMTP 550 to its
 * reader -- fails the job at once (JobRefused), since every retry would be
 * refused the same way, and spend the provider's patience doing it.
 */
#[Queue(self::QUEUE, tries: 5, backoff: [10, 60, 300, 900], timeout: self::TIMEOUT)]
final readonly class SendMail implements Job
{
    public const string QUEUE = 'mail';

    /** Seconds a send is given: a failover's deadline fits inside it (FailoverTransport::DEADLINE). */
    public const int TIMEOUT = 60;

    /** JobRefused::$reason for a message a transport refused for good. */
    public const string REFUSED = 'mail_refused';

    public function __construct(public Mailable $mailable) {}

    public function handle(Mailer $mailer): void
    {
        self::attempt(fn (): Message => $mailer->send($this->mailable));
    }

    /**
     * Runs $send, and turns a permanent MailTransportException into the
     * refusal that fails a job at once; a retryable one is thrown as it is,
     * for the job's backoff. For an application's own mail job -- a
     * newsletter's, which asks about the reader first -- as for this one.
     *
     * @template T
     * @param \Closure(): T $send
     * @return T
     */
    public static function attempt(\Closure $send): mixed
    {
        try {
            return $send();
        } catch (MailTransportException $e) {
            if ($e->retryable) {
                throw $e;
            }

            throw new JobRefused(self::REFUSED, $e->getMessage(), $e);
        }
    }
}
