<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

/**
 * A fetch that completed and whose answer was not what was asked for: a
 * status other than a 2xx, a download whose digest is not the one it was
 * fetched against, an image that is not one of the types taken, or one too
 * large to decode. The transport's own refusals and failures are
 * OutboundRefused and OutboundFailed, thrown as they are.
 *
 * `reason` is a code a caller may show; the message names the destination
 * by its origin alone, as OutboundHttp's do, and never quotes what the
 * server sent.
 */
final class RemoteContentRefused extends \RuntimeException
{
    public const string STATUS = 'status';
    public const string CHECKSUM = 'checksum';
    public const string TYPE = 'type';
    public const string DIMENSIONS = 'dimensions';

    public function __construct(public readonly string $reason, string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
