<?php

declare(strict_types=1);

namespace Tinvar\Channels;

use Tinvar\Auth\Gate;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\HttpException;
use Tinvar\Http\NotFoundException;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;

/**
 * Lets one subscription in, in the order the security model sets out for
 * every entry point -- its tenant, panel stack and middleware having run
 * around it:
 *
 *   1. resolve each model parameter scoped to the tenant; a missing or
 *      foreign id is a 404, as a page's is
 *   2. run every #[Authorize]; denied is a 403
 *
 * and answers with the channel's parameters as the server holds them --
 * each model's primary key off the row it found, each scalar in one
 * spelling -- which the topic is worked out from. Run when a client
 * subscribes, and again on every trade of its token (ChannelEndpoint).
 */
final readonly class ChannelRunner
{
    /** The longest string parameter: an address, not a payload. */
    public const int MAX_SCALAR_BYTES = 200;

    public function __construct(private Orm $orm, private Gate $gate) {}

    /**
     * @param array<array-key, mixed> $input the parameters as a client or a token gave them
     * @return array<string, string> the parameters as the server holds them, in the pattern's order
     * @throws HttpException 400 for a parameter the channel does not take; 404 for one it cannot
     *         resolve; 403 for a check that denies
     */
    public function admit(CompiledChannel $channel, array $input): array
    {
        foreach (array_keys($input) as $key) {
            if (!\in_array($key, $channel->parameters, true)) {
                throw new HttpException(400, "The channel '{$channel->name}' takes no parameter by that name.", 'bad_request');
            }
        }

        // 1. Resolve.
        $models = [];
        $params = [];
        foreach ($channel->parameters as $name) {
            $value = $input[$name] ?? null;
            $model = $channel->models[$name] ?? null;
            if ($model !== null) {
                if ((!\is_string($value) || $value === '') && !\is_int($value)) {
                    throw new NotFoundException("The channel '{$channel->name}' needs an identifier for '{$name}'.");
                }
                // Out of scope reads as absent, never as forbidden.
                $found = $this->orm->find($model, (string) $value) ?? throw NotFoundException::model($model, (string) $value);
                $models[$name] = $found;
                $params[$name] = $found->primaryKey();
                continue;
            }

            $params[$name] = self::scalar($channel, $name, $value)
                ?? throw new NotFoundException("The channel '{$channel->name}' has no '{$name}' by that value.");
        }

        // 2. Authorize: every check, each against what it names.
        foreach ($channel->checks as $check) {
            if (!$this->gate->allows($check, $models)) {
                throw ForbiddenException::check($check->ability, $channel->class);
            }
        }

        return $params;
    }

    /**
     * A scalar parameter in the one spelling the topic is worked out from,
     * so `7` and `"07"` are one channel or none; null for a value that is
     * not one of the type's.
     */
    public static function scalar(CompiledChannel $channel, string $name, mixed $value): ?string
    {
        $type = $channel->scalars[$name] ?? null;

        return match (true) {
            $type === null => null,
            $type === 'string' => \is_string($value) && $value !== '' && \strlen($value) <= self::MAX_SCALAR_BYTES && preg_match('//u', $value) === 1
                ? $value
                : null,
            $type === 'int' => match (true) {
                \is_int($value) => (string) $value,
                \is_string($value) && preg_match('/^(0|-?[1-9][0-9]{0,17})\z/', $value) === 1 => $value,
                default => null,
            },
            $type === 'bool' => match ($value) {
                true, '1', 'true' => '1',
                false, '0', 'false' => '0',
                default => null,
            },
            is_subclass_of($type, \BackedEnum::class) => (\is_string($value) || \is_int($value))
                ? self::enumValue($type, $value)
                : null,
            default => null,
        };
    }

    /** @param class-string<\BackedEnum> $enum */
    private static function enumValue(string $enum, string|int $value): ?string
    {
        // tryFrom() of an int-backed enum takes no string, and of a
        // string-backed one no int: each is offered what it is backed by.
        $backing = (string) (new \ReflectionEnum($enum))->getBackingType();
        if ($backing === 'int') {
            if (\is_string($value) && preg_match('/^(0|-?[1-9][0-9]{0,17})\z/', $value) !== 1) {
                return null;
            }
            $case = $enum::tryFrom((int) $value);
        } else {
            $case = \is_string($value) ? $enum::tryFrom($value) : null;
        }

        return $case === null ? null : (string) $case->value;
    }

    /**
     * A channel instance's parameters as the server holds them, for
     * publishing -- read off its promoted properties, each model's primary
     * key off the model -- with the models among them.
     *
     * @return array{array<string, string>, array<string, Model>}
     */
    public static function addressOf(CompiledChannel $channel, object $instance): array
    {
        $params = [];
        $models = [];
        $reflection = new \ReflectionObject($instance);
        foreach ($channel->parameters as $name) {
            $value = $reflection->getProperty($name)->getValue($instance);
            if (isset($channel->models[$name])) {
                if (!$value instanceof Model || !Orm::holdsRow($value)) {
                    throw new \LogicException("{$channel->where()}: \${$name} holds no row, so it names no channel to publish to.");
                }
                $models[$name] = $value;
                $params[$name] = $value->primaryKey();
                continue;
            }

            $params[$name] = self::scalar($channel, $name, $value instanceof \BackedEnum ? $value->value : $value)
                ?? throw new \LogicException("{$channel->where()}: \${$name} holds a value no subscriber could name.");
        }

        return [$params, $models];
    }
}
