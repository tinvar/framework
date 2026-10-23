<?php

declare(strict_types=1);

namespace Tinvar\Validation;

use Tinvar\Http\HttpException;

/**
 * A 422 carrying typed field errors.
 *
 * "With JavaScript, a failed action returns errors with the payload (no
 * redirect)." The error bag is the payload; the client maps it onto fields.
 */
final class ValidationException extends HttpException
{
    /** @param array<string, list<string>> $errors field => messages */
    public function __construct(public readonly array $errors)
    {
        $count = array_sum(array_map(\count(...), $errors));
        parent::__construct(422, "Validation failed with {$count} error(s).", 'validation_failed');
    }

    /** @return list<string> */
    public function for(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /** @return list<string> */
    public function fields(): array
    {
        return array_keys($this->errors);
    }

    public function has(string $field): bool
    {
        return isset($this->errors[$field]);
    }
}
