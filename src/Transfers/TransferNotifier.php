<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Auth\Authenticatable;

/**
 * Told when an export or import a user started has finished, either way:
 * the application's, to send the email or the notification it chooses.
 *
 * Called on the worker, in the job's own context -- as the user, in their
 * tenant and panel -- once the record says what came of it. For a finished
 * export, $link is its download link on the user's canonical https origin
 * (Transfers::emailedLink()), never one built from a request's Host; null
 * when the application gave no origin, or the export failed.
 *
 * A failure here is reported and does not undo the transfer: the file is
 * written, or the rows applied, whether or not the email went.
 */
interface TransferNotifier
{
    public function finished(TransferRecord $record, Authenticatable $user, ?string $link): void;
}
