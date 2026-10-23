<?php

declare(strict_types=1);

namespace Tinvar\Protocol;

use Tinvar\Codec\Bin;

/**
 * The socket protocol's frame table (tinvar-spec.md, "Socket protocol").
 *
 * Direction is enforced, not merely documented. The security model requires
 * one-way frames: "The renderer sends output chunks, query calls, head tags and
 * done or error. It cannot set flash data or anything else that PHP persists."
 */
final class Frame
{
    // PHP -> renderer
    public const string RENDER = 'render';
    public const string FRAGMENT = 'fragment';
    public const string RESULT = 'result';

    // renderer -> PHP
    public const string CHUNK = 'chunk';
    public const string QUERY = 'query';
    public const string META = 'meta';
    public const string DONE = 'done';
    public const string ERROR = 'error';

    /** @var list<string> */
    public const array PHP_TO_RENDERER = [self::RENDER, self::FRAGMENT, self::RESULT];

    /** @var list<string> */
    public const array RENDERER_TO_PHP = [self::CHUNK, self::QUERY, self::META, self::DONE, self::ERROR];

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $loaderData  layout and page loader data, already encoded
     * @param list<string>         $allowedQueries per-route query allowlist
     * @return array<string, mixed>
     */
    public static function render(
        string $routeId,
        array $params,
        array $loaderData,
        string $requestId,
        string $cspNonce,
        array $allowedQueries,
    ): array {
        return [
            't' => self::RENDER,
            'route' => $routeId,
            'params' => $params,
            'data' => $loaderData,
            'rid' => $requestId,
            'nonce' => $cspNonce,
            'allow' => array_values($allowedQueries),
        ];
    }

    /**
     * @param array<string, mixed> $token already-verified fragment token contents
     * @param list<string>         $allowedQueries
     * @return array<string, mixed>
     */
    public static function fragment(array $token, string $requestId, string $cspNonce, array $allowedQueries): array
    {
        return [
            't' => self::FRAGMENT,
            'token' => $token,
            'rid' => $requestId,
            'nonce' => $cspNonce,
            'allow' => array_values($allowedQueries),
        ];
    }

    /**
     * One reply per call in the batch, in any order; `id` pairs them up.
     * Errors are classified codes in production, never exception messages.
     *
     * @param list<array{id: int, value?: mixed, error?: array{code: string, digest: string}}> $results
     * @return array<string, mixed>
     */
    public static function result(array $results): array
    {
        return ['t' => self::RESULT, 'r' => $results];
    }

    /** @return array<string, mixed> */
    public static function chunk(string $bytes): array
    {
        return ['t' => self::CHUNK, 'b' => new Bin($bytes)];
    }

    /**
     * Rejects any frame the renderer is not allowed to originate.
     *
     * @param array<string, mixed> $frame
     * @return non-empty-string the validated frame type
     */
    public static function assertFromRenderer(array $frame): string
    {
        $type = $frame['t'] ?? null;
        if (!\is_string($type) || $type === '') {
            throw ProtocolException::unknownType($type);
        }
        if (!\in_array($type, self::RENDERER_TO_PHP, true)) {
            throw ProtocolException::wrongDirection($type);
        }

        return $type;
    }

    /**
     * Pulls the `{id, name, args}` calls out of a query frame, checking shape
     * before any of them reach the registry.
     *
     * @param array<string, mixed> $frame
     * @return list<array{id: int, name: string, args: array<string, mixed>}>
     */
    public static function readQueryBatch(array $frame): array
    {
        $calls = $frame['q'] ?? null;
        if (!\is_array($calls) || !array_is_list($calls)) {
            throw ProtocolException::malformed(self::QUERY, "'q' must be a list of calls");
        }

        $out = [];
        foreach ($calls as $call) {
            if (!\is_array($call)) {
                throw ProtocolException::malformed(self::QUERY, 'each call must be a map');
            }
            $id = $call['id'] ?? null;
            $name = $call['name'] ?? null;
            $args = $call['args'] ?? [];

            if (!\is_int($id)) {
                throw ProtocolException::malformed(self::QUERY, "call 'id' must be an int");
            }
            if (!\is_string($name) || $name === '') {
                throw ProtocolException::malformed(self::QUERY, "call 'name' must be a non-empty string");
            }
            if (!\is_array($args)) {
                throw ProtocolException::malformed(self::QUERY, "call 'args' must be a map");
            }

            /** @var array<string, mixed> $args */
            $out[] = ['id' => $id, 'name' => $name, 'args' => $args];
        }

        return $out;
    }
}
