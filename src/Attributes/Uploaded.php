<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * An action parameter that takes an upload, and what may be uploaded to it.
 *
 *     #[Action, Authorize('update', 'customer')]
 *     public function updateAvatar(
 *         Customer $customer,
 *         #[Uploaded(to: 'customer', prefix: 'avatars', types: ['image/png', 'image/jpeg'], maxBytes: 2_097_152)]
 *         Upload $avatar,
 *     ): ActionResult
 *
 * tinvar-spec.md: "Uploaded files are referenced only by a server-issued
 * upload ticket bound to user, tenant, target model, key prefix and expiry."
 * The declaration is where those come from, beside the code that uses the
 * file: a page issues a ticket for this parameter (UploadTickets), the
 * browser sends the file to /_upload with it, and the action is called with
 * the reference /_upload answered. Every limit here is enforced at
 * /_upload, from this declaration, never from anything the ticket or the
 * client says.
 *
 * - `to` names the action's model parameter the file is attached to. A
 *   ticket names one record of it, and the upload is bound to it: called
 *   with another record, the action is refused the upload. `to: null` is
 *   for a record not yet created -- the action that takes the file is the
 *   one that makes it -- and binds the upload to no record: still to the
 *   action and parameter, the session, the user at their auth epoch, the
 *   tenant and the panel, and to nothing a record could be swapped for,
 *   since there is none (ADR 0127). Said out loud, never a default.
 * - `prefix` is where the file's storage key goes, below which the
 *   framework draws the rest of the key at random.
 * - `types` are the media types the bytes may sniff as -- from what
 *   Storage\FileTypes recognises, which is never one a browser would run.
 * - `maxBytes` is the largest file taken, under the application's own
 *   ceiling (the uploads' own maxBytes).
 * - `maxFiles` is how many files the parameter takes: 1 for an `Upload`,
 *   2 or more for an `UploadList`, whose files each go to /_upload under
 *   the one ticket and are each held to all of the above.
 *
 * ActionRegistry refuses a declaration it could not hold an upload to.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Uploaded
{
    /** The most files one parameter takes: each is a file kept until it is claimed or pruned. */
    public const int MAX_FILES = 20;

    /** @var list<string> */
    public array $types;

    /** @param array<mixed> $types as written, checked here */
    public function __construct(
        public ?string $to,
        public string $prefix,
        array $types,
        public int $maxBytes = 5_242_880,
        public int $maxFiles = 1,
    ) {
        $list = [];
        foreach ($types as $type) {
            if (!\is_string($type)) {
                throw new \InvalidArgumentException('#[Uploaded] types are media types, as strings.');
            }
            $list[] = $type;
        }

        $this->types = $list;
    }
}
