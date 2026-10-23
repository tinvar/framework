<?php

declare(strict_types=1);

namespace Tinvar\Http\Endpoints;

use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRunner;

/**
 * POST /_query/{name}, for `useHttp` -- calling a query without navigating.
 *
 * The allowlist still applies. Over the render socket it comes from the
 * route's component graph; here the caller must say which route it is acting
 * for, and the mounting code supplies that route's list. A query reachable
 * over HTTP that the page never uses is the same hole either way.
 */
final class QueryEndpoint
{
    public const string PREFIX = '/_query/';

    /** @param \Closure(Request): list<string> $allowedFor */
    public function __construct(
        private readonly QueryRunner $runner,
        private readonly \Closure $allowedFor,
        private readonly int $maxBodyBytes = Limits::MAX_BODY_BYTES,
    ) {}

    public function matches(Request $request): bool
    {
        return str_starts_with($request->path, self::PREFIX);
    }

    public function handle(Request $request, string $rawBody = ''): Response
    {
        if ($request->method !== 'POST') {
            // POST, not GET: arguments are a JSON body, and a GET would be
            // cached and logged by every intermediary.
            throw new HttpException(405, 'Queries over HTTP are POST only.', 'method_not_allowed');
        }

        $name = substr($request->path, \strlen(self::PREFIX));
        if ($name === '') {
            throw new HttpException(404, 'No query named in the path.', 'not_found');
        }

        $body = $rawBody === '' ? $request->body : Limits::decodeJson($rawBody, $this->maxBodyBytes);
        $args = $body['args'] ?? [];

        // {} decodes to [], which array_is_list() calls a list: an
        // argument-less query was unreachable over HTTP.
        if (!\is_array($args) || ($args !== [] && array_is_list($args))) {
            throw new HttpException(400, 'Query arguments must be a JSON object.', 'bad_request');
        }

        /** @var array<string, mixed> $args */
        $results = $this->runner->run(
            [['id' => 0, 'name' => $name, 'args' => $args]],
            ($this->allowedFor)($request),
        );

        $result = $results[0];

        // A denial, a name that is not allowed and bad arguments are different
        // answers; collapsing them all to 422 left the client unable to tell
        // "re-authenticate" from "fix your input".
        return Response::make(
            isset($result['error']) ? QueryError::statusFor($result['error']['code']) : 200,
            json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ['content-type' => 'application/json', 'cache-control' => 'private, no-store', 'vary' => 'Cookie'],
        );
    }
}
