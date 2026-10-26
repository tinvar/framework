<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * A DNS lookup that did not complete: a server failure, a timeout, or no
 * time left to start it. Not an answer about where a name points -- an
 * empty answer is one -- so a domain is never deactivated at once for it
 * (CustomDomainVerification).
 */
final class DnsLookupFailed extends \RuntimeException {}
