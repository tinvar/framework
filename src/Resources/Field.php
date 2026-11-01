<?php

declare(strict_types=1);

namespace Tinvar\Resources;

/**
 * One input of a resource's create and edit forms.
 *
 *     Field::make('name')->max(120),
 *     Field::make('description')->textarea(),
 *     Field::make('priceCents', 'Price in cents')->min(0),
 *     Field::make('customer')->labelledBy('email'),
 *
 * Named by the model's property, which says what the field takes: text, a
 * number, on or off, a moment, one of an enum's cases, or one of the
 * records it may belong to -- each read back as that type, or refused with
 * a message the form shows beside it. Required unless the property may be
 * null. Only what the fields name is ever written: a key the form did not
 * declare is never read, so a post cannot set the id, a total or anything
 * else.
 */
final readonly class Field
{
    private function __construct(
        public string $key,
        public ?string $label = null,
        public bool $textarea = false,
        public ?string $help = null,
        public int|float|null $min = null,
        public int|float|null $max = null,
        public ?string $labelledBy = null,
    ) {}

    /**
     * @param string $key the model's property
     * @param string|null $label what the input is labelled, `Price cents` for priceCents by default
     */
    public static function make(string $key, ?string $label = null): self
    {
        return new self($key, $label);
    }

    /** Text over several lines. */
    public function textarea(): self
    {
        return clone($this, ['textarea' => true]);
    }

    /** A line under the input saying what it is for. */
    public function help(string $help): self
    {
        return clone($this, ['help' => $help]);
    }

    /** At least this: a number's value, or a text's characters. */
    public function min(int|float $min): self
    {
        return clone($this, ['min' => $min]);
    }

    /** At most this: a number's value, or a text's characters. */
    public function max(int|float $max): self
    {
        return clone($this, ['max' => $max]);
    }

    /** For a record it belongs to: the property each choice is labelled by, `name` by default. */
    public function labelledBy(string $property): self
    {
        return clone($this, ['labelledBy' => $property]);
    }
}
