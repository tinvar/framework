<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Jobs\Queueable;

/**
 * An email the application sends, described as data: the order it is
 * about, the customer it is for. Queueable, so Mailer::queue() can hand it
 * to a worker with its models as ids, and the worker builds the email --
 * reading those models again -- when it sends.
 */
interface Mailable extends Queueable
{
    public function email(): Email;
}
