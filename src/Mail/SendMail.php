<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Attributes\Queue;
use Tinvar\Jobs\Job;

/**
 * A queued send: the mailable travels as data, and the email is built and
 * rendered on the worker.
 *
 * On its own queue, so a backlog of mail does not hold up other work, and
 * tried for a while before it fails: a mail server that is down is usually
 * back within the hour.
 */
#[Queue(self::QUEUE, tries: 5, backoff: [10, 60, 300, 900], timeout: 60)]
final readonly class SendMail implements Job
{
    public const string QUEUE = 'mail';

    public function __construct(public Mailable $mailable) {}

    public function handle(Mailer $mailer): void
    {
        $mailer->send($this->mailable);
    }
}
