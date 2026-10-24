<?php

declare(strict_types=1);

namespace Tinvar\Observability;

use Tinvar\Http\Request;

/**
 * The id one request is known by: in every log line PHP writes for it, in
 * the renderer's own log, in its spans and in the response's X-Request-Id.
 * tinvar-spec.md asks for a "shared request ID in both logs"; this is it.
 *
 * It is the request's trace id -- 128 random bits as 32 lowercase hex
 * digits, the form W3C Trace Context and OpenTelemetry give one -- so a log
 * line and the trace it belongs to are found by the same string, and there
 * is one id to quote, not two.
 */
final class RequestId
{
    /** The request attribute Telemetry::begin() sets it under. */
    public const string ATTRIBUTE = 'tinvar.request_id';

    /** The response header that hands it back, for a client to quote. */
    public const string HEADER = 'x-request-id';

    /** All zeros is W3C's invalid trace id, and never one this class makes. */
    private const string INVALID = '00000000000000000000000000000000';

    public static function generate(): string
    {
        do {
            $id = bin2hex(random_bytes(16));
        } while ($id === self::INVALID);

        return $id;
    }

    /** Whether $id has the one shape a request id takes here. */
    public static function isValid(string $id): bool
    {
        return preg_match('/^[0-9a-f]{32}\z/', $id) === 1 && $id !== self::INVALID;
    }

    /**
     * The request's id, as Telemetry gave it -- what an application puts in
     * the render frame's `rid`, so the renderer logs under the same id. A
     * request Telemetry never saw gets a fresh one, which correlates nothing
     * but is still a valid id.
     */
    public static function of(Request $request): string
    {
        $id = $request->attribute(self::ATTRIBUTE);

        return \is_string($id) && self::isValid($id) ? $id : self::generate();
    }
}
