<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * 413: a raw request body larger than Sapi::MAX_BODY_BYTES, refused by its
 * Content-Length before a byte is read, or once the read runs past it
 * (ADR 0207). Thrown before the request reaches the application.
 */
final class BodyTooLarge extends HttpException
{
    public function __construct()
    {
        parent::__construct(413, 'Request body is too large.', 'body_too_large');
    }
}
