<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Impersonation;

use Tinvar\Http\HttpException;

/**
 * An impersonation refused, or a request refused for being one (ADR 0194):
 * a 403 coded `impersonation_refused` -- a 401 for nobody signed in -- with
 * the reason on $refusal.
 */
final class ImpersonationRefused extends HttpException
{
    public const string CODE = 'impersonation_refused';

    public function __construct(public readonly ImpersonationRefusal $refusal)
    {
        parent::__construct($refusal === ImpersonationRefusal::NotSignedIn ? 401 : 403, $refusal->message(), self::CODE);
    }
}
