<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Attributes\Queue;
use Tinvar\Jobs\Job;
use Tinvar\Mail\SendMail;

/**
 * An account email, worked out and sent on the queue worker.
 *
 * The request that asks queues this whatever the address -- one row,
 * written the same way -- and answers the same, so neither the answer nor
 * the time it takes says whether the address has an account. Whether it
 * does is found out here, where nobody is waiting (Accounts::deliver()).
 *
 * The link is made here too, and sent at once rather than queued again: a
 * link in a queued mailable would be a credential in the jobs table. A
 * retry makes a new one, and the first, if it was sent, still opens until
 * the account's epoch moves or it expires.
 */
#[Queue(SendMail::QUEUE, tries: 5, backoff: [10, 60, 300, 900], timeout: 60)]
final readonly class SendAccountMail implements Job
{
    /**
     * @param AccountMailKind $kind which email
     * @param string $email the address typed -- for a sign-up, a reset or a new
     *        address -- or the one an account moved away from
     * @param string $name the name a sign-up gave
     * @param string $userId the account, for what is about one already known
     */
    public function __construct(
        public AccountMailKind $kind,
        public string $email = '',
        public string $name = '',
        public string $userId = '',
    ) {}

    public function handle(Accounts $accounts): void
    {
        $accounts->deliver($this);
    }
}
