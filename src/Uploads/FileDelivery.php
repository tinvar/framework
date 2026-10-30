<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

/**
 * How /_file sends a file kept in an object store (Storage\ObjectStore).
 *
 * - Stream: PHP reads it from the store and sends it on, chunk by chunk,
 *   under every header /_file sets -- `nosniff`, the sandboxing policy, the
 *   disposition -- from the application's own origin. The default.
 * - Redirect: a 302 to a URL presigned for REDIRECT_TTL seconds, so the
 *   bytes go from the store to the browser and never through a worker. The
 *   store's response carries the type, the disposition and `private,
 *   no-store`, which a presigned URL can set; it cannot set `nosniff` or a
 *   Content-Security-Policy, so the file is served from the store's origin,
 *   never the application's -- whatever it held could run nowhere that
 *   reaches the application's cookies. A page showing such an image names
 *   the store's origin in its img-src.
 *
 * A file on local disk or in memory is always streamed.
 */
enum FileDelivery
{
    case Stream;
    case Redirect;

    /** Seconds a redirect's presigned URL is good for: enough to follow it. */
    public const int REDIRECT_TTL = 60;
}
