<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * 404.
 *
 * Also what a missing or foreign model id produces: the security model says a
 * row outside the active tenant is not found, never forbidden, so the response
 * cannot be used to probe for rows in another tenant.
 */
final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not found.')
    {
        parent::__construct(404, $message, 'not_found');
    }

    /**
     * The id is not interpolated. It comes from the request, and these
     * messages reach logs, where a control character forges lines.
     */
    public static function model(string $class, string $id): self
    {
        return new self("No {$class} with the given id in the active scope.");
    }

    /**
     * The path is not interpolated: it is fully client-controlled, and these
     * messages reach logs. A CRLF in a path would forge log lines.
     */
    public static function route(string $path): self
    {
        return new self('No route matches this path.');
    }
}
