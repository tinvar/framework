<?php

declare(strict_types=1);

namespace Tinvar\Tests\Validation\Fixtures;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Email;
use Tinvar\Validation\Rules\In;

enum Urgency: string
{
    case Low = 'low';
    case High = 'high';
}

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class NoisyAttribute
{
    public function __construct()
    {
        throw new \RuntimeException('an attribute that is not a rule must not be instantiated here');
    }
}

/**
 * Declaring these is legal; planning them is not. Each is an authoring mistake
 * that used to surface as a TypeError on the first request, or as a rule that
 * silently passed everything.
 */

final readonly class UnionFieldRequest extends FormRequest
{
    public function __construct(public int|string $x) {}
}

final readonly class UnsupportedClassRequest extends FormRequest
{
    public function __construct(public \DateTimeImmutable $when) {}
}

final readonly class IterableFieldRequest extends FormRequest
{
    public function __construct(public iterable $it) {}
}

final readonly class VariadicRequest extends FormRequest
{
    /** @var list<string> */
    public array $tags;

    public function __construct(string ...$tags)
    {
        $this->tags = $tags;
    }
}

final readonly class EmailOnBoolRequest extends FormRequest
{
    public function __construct(#[Email] public bool $flag) {}
}

final readonly class InOnEnumRequest extends FormRequest
{
    public function __construct(#[In('low')] public Urgency $urgency) {}
}

final readonly class NoisyAttributeRequest extends FormRequest
{
    public function __construct(#[NoisyAttribute] public string $a) {}
}
