<?php

declare(strict_types=1);

namespace Tinvar\Http\Endpoints;

use Tinvar\Actions\ActionRunner;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Validation\ValidationException;

/**
 * POST /_action/{name}
 *
 * "Actions are always POST, so no `_method` spoofing." The middleware stack --
 * web, auth, throttle:actions, plus the action's panel stack -- is applied by
 * whoever mounts this; the endpoint itself owns the body limits, the method,
 * and turning a result or a failure into a response.
 */
final class ActionEndpoint
{
    public const string PREFIX = '/_action/';

    public function __construct(
        private readonly ActionRunner $runner,
        private readonly int $maxBodyBytes = Limits::MAX_BODY_BYTES,
    ) {}

    public function matches(Request $request): bool
    {
        return str_starts_with($request->path, self::PREFIX);
    }

    public function handle(Request $request, string $rawBody = ''): Response
    {
        if ($request->method !== 'POST') {
            throw new HttpException(405, 'Actions are POST only.', 'method_not_allowed');
        }

        $name = substr($request->path, \strlen(self::PREFIX));
        if ($name === '') {
            throw new HttpException(404, 'No action named in the path.', 'not_found');
        }

        $input = $rawBody === '' ? $request->body : Limits::decodeJson($rawBody, $this->maxBodyBytes);

        // "Precognition / Precognition-Validate-Only": validate without
        // running the body, optionally one field at a time.
        $precognitive = $request->header('precognition') === 'true';
        $only = $request->header('precognition-validate-only');

        try {
            if ($precognitive && $only !== null && $only !== '') {
                $fields = explode(',', $only);

                // The header is client-supplied and each field re-runs model
                // resolution and authorization, which the body limit does not
                // bound.
                if (\count($fields) > Limits::MAX_PRECOGNITION_FIELDS) {
                    throw new HttpException(
                        400,
                        'Too many fields named in Precognition-Validate-Only.',
                        'bad_request',
                    );
                }

                $errors = [];
                foreach ($fields as $field) {
                    $field = trim($field);

                    try {
                        $found = $this->runner->validateField($name, $input, $field);
                    } catch (\LogicException) {
                        // validateField() refuses an unknown field rather than
                        // returning [], which would read as "this field is
                        // fine" -- right for an author typo, wrong for a name
                        // a client chose. Here it is bad input, not a bug, and
                        // the exception message quotes the client's own text
                        // back, which the error-channel rule forbids.
                        throw new HttpException(
                            422,
                            'Precognition-Validate-Only names a field this action does not have.',
                            'unprocessable',
                        );
                    }

                    if ($found !== []) {
                        $errors[$field] = $found;
                    }
                }

                return $errors === []
                    ? self::json(204, [])
                    : self::json(422, ['errors' => $errors]);
            }

            $result = $this->runner->run($name, $input, $precognitive, $request);
        } catch (ValidationException $e) {
            // "With JavaScript, a failed action returns errors with the
            // payload (no redirect)."
            return self::json(422, ['errors' => $e->errors]);
        }

        if ($result->isRedirect()) {
            // 303 after a write, so a reload does not repeat it.
            return Response::redirect((string) $result->redirect, 303)
                ->withHeader('x-invalidated-tags', implode(',', $result->revalidatedTags));
        }

        return self::json($result->status, [
            'value' => $result->value,
            'flash' => $result->flash,
            'revalidated' => $result->revalidatedRoutes,
        ])
            // "an action's response lists the server-side query tags it
            // invalidated, and the client flushes matching prefetch entries
            // automatically."
            ->withHeader('x-invalidated-tags', implode(',', $result->revalidatedTags));
    }

    /** @param array<string, mixed> $payload */
    private static function json(int $status, array $payload): Response
    {
        return Response::make(
            $status,
            $status === 204 ? '' : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ['content-type' => 'application/json', 'cache-control' => 'private, no-store'],
        );
    }
}
