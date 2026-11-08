<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Auth\Authenticatable;
use Tinvar\Http\Request;

/**
 * How the session a handoff starts from was signed in, for the code to
 * carry to the session it starts (ADR 0184): HandoffSessions that know it
 * implement this too, and HandoffEndpoint mints it into the code -- under
 * the code's MAC, so nobody between the hosts writes it -- and hands it to
 * arrive() as Arrival::$factors. A session that arrives was signed in with
 * what the one it came from proved, and no more.
 *
 * Opaque here: the application's sessions write it and read it back, and
 * '' is nothing proved.
 */
interface HandoffFactors
{
    /**
     * What the session $request carries proved, written for its own
     * arrive() to read back; '' unless that session is signed in as $user.
     */
    public function factorsOf(Request $request, Authenticatable $user): string;
}
