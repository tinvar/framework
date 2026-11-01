<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Http\ApcuThrottleStore;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Throttle;
use Tinvar\Http\ThrottleResult;
use Tinvar\Http\ThrottleStore;

/**
 * Asking for an emailed link -- a sign-up, a sign-in link, a password
 * reset, a change of address -- counted per address and per recipient
 * (ADR 0076).
 *
 * - Per address: requests from one address, for any email, refused with a
 *   429 past the limit -- one address cannot send mail to a list of
 *   strangers, or walk a list of addresses.
 * - Per recipient: requests for one email from every address together.
 *   Past the limit the request is answered exactly as any other, and no
 *   mail goes: an inbox is not flooded by someone else's requests, and the
 *   answer still says nothing about whether the address has an account. A
 *   429 here would not say so either -- the count is the same whoever's
 *   address it is -- but it would tell a stranger the owner had been
 *   asking.
 *
 * Counted whether or not the email has an account, and before anything is
 * looked up, so neither the answer nor its timing tells the two apart. In
 * the store sign-in counts in, which nothing else fills: never APCu
 * (SignInThrottle, ADR 0023).
 */
final readonly class LinkRequestThrottle
{
    /** Requests from one address, per window. */
    public const int PER_ADDRESS = 10;
    public const int ADDRESS_WINDOW = 900;

    /** Mail sent to one recipient, per window. */
    public const int PER_RECIPIENT = 5;
    public const int RECIPIENT_WINDOW = 3_600;

    /**
     * @param int $perAddress 0 turns the per-address limit off
     * @param int $perRecipient 0 turns the per-recipient limit off
     */
    public function __construct(
        private ThrottleStore $store,
        private Throttle $throttle,
        private int $perAddress = self::PER_ADDRESS,
        private int $perRecipient = self::PER_RECIPIENT,
    ) {
        if ($store instanceof ApcuThrottleStore) {
            throw new \InvalidArgumentException('Link requests are not counted in APCu: a full APCu clears every entry, and anyone can fill it.');
        }
    }

    /**
     * Counts one request, refusing it with a 429 past the address's limit,
     * and says whether to send its mail.
     */
    public function hit(Request $request, string $email): bool
    {
        if ($this->perAddress > 0) {
            [$used, $resetsAt] = $this->store->increment('email-link|ip|' . $this->throttle->addressFor($request), self::ADDRESS_WINDOW);
            if ($used > $this->perAddress) {
                $verdict = new ThrottleResult(allowed: false, limit: $this->perAddress, remaining: 0, resetsAt: $resetsAt);

                throw new HttpException(429, 'Too many requests for an email.', 'too_many_attempts', $verdict->headers());
            }
        }

        if ($this->perRecipient <= 0) {
            return true;
        }
        [$sent] = $this->store->increment('email-link|to|' . hash('sha256', mb_strtolower(trim($email))), self::RECIPIENT_WINDOW);

        return $sent <= $this->perRecipient;
    }
}
