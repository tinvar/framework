<?php

declare(strict_types=1);

namespace Tinvar\Config;

use Tinvar\Attributes\Config;
use Tinvar\Attributes\Env;
use Tinvar\Auth\Violation;
use Tinvar\Data\Data;
use Tinvar\Data\ProvidesData;

/**
 * Loads #[Config] classes from the environment (ADR 0085).
 *
 *     $config = Configs::load(ShopConfig::class);
 *
 * Each constructor parameter of the class is one key. Its variable is read,
 * parsed to the parameter's type and passed in; an unset or empty variable
 * takes the parameter's default, or null where it is nullable, and is
 * refused where it has neither. The types are the ones a variable can say
 * without guessing: string, int, float, bool (`1` or `0`, as every
 * TINVAR_ switch is), a backed enum, and Secret. Anything else -- `yes`
 * for a bool, `12abc` for an int, a case the enum has not -- is refused,
 * never read as something near it: a value that is not what was meant
 * stops the boot rather than run the app on a guess.
 *
 * Every key is read and every problem collected before anything is
 * thrown, so one ConfigException says all that is wrong. Its messages name
 * variables and never values. The class's constructor runs last, and may
 * refuse what the types cannot -- a token too short, a URL without the
 * secret it needs -- with ConfigException::invalid(); anything else it
 * throws is reported by its class alone, since its message may quote a
 * value.
 *
 * A worker loads each class once. The object is kept with a digest of the
 * variables it was read from, and handed back while they are the same --
 * which, in a worker, is for its life: a changed variable, or a rotated
 * secret file, needs the worker restarted, as a new build does. It holds
 * no request's state, so the reset contract has nothing of it to clear.
 * A test that sets a variable gets a fresh object, since the digest no
 * longer matches; one that hands load() an environment of its own is
 * never cached at all.
 *
 * What a class may be is held at boot and by `tinvar check` alike: final
 * and readonly, so no one changes it after boot; never Data, nor anything
 * the encoder or the mapper would read; every Secret parameter
 * #[\SensitiveParameter], and every #[\SensitiveParameter] one a Secret, so
 * a secret is never a string a dump or a trace could print; and no default
 * for a secret, since a secret in the source is no secret.
 */
final class Configs
{
    /** A file a secret is read from is no larger than this. */
    public const int MAX_FILE_BYTES = 65_536;

    /** @var array<class-string, list<ConfigKey>> */
    private static array $keys = [];

    /** @var array<string, bool> */
    private static array $isConfig = [];

    /** @var array<class-string, array{0: string, 1: object}> class => [digest of what it was read from, the object] */
    private static array $loaded = [];

    /**
     * The config object, from the environment -- or from $env, a test's,
     * which is never cached.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, string>|null $env
     * @return T
     * @throws ConfigException for a key missing or malformed, or refused by the class
     * @throws \LogicException for a class that is no config class
     */
    public static function load(string $class, ?array $env = null): object
    {
        $keys = self::keys($class);
        $raw = self::raw($keys, $env);

        $digest = null;
        if ($env === null) {
            $digest = hash('sha256', serialize($raw));
            $kept = self::$loaded[$class] ?? null;
            if ($kept !== null && hash_equals($kept[0], $digest)) {
                $object = $kept[1];
                \assert($object instanceof $class);

                return $object;
            }
        }

        [$object, $problems] = self::build($class, $keys, $raw);
        if (!$object instanceof $class) {
            throw new ConfigException($problems);
        }

        if ($digest !== null) {
            self::$loaded[$class] = [$digest, $object];
        }

        return $object;
    }

    /**
     * Each class loaded, as a boot does before anything opens: every
     * problem in every class, in one exception.
     *
     * @param list<class-string> $classes
     * @param array<string, string>|null $env
     * @throws ConfigException
     */
    public static function loadAll(array $classes, ?array $env = null): void
    {
        $problems = [];
        foreach ($classes as $class) {
            try {
                self::load($class, $env);
            } catch (ConfigException $e) {
                array_push($problems, ...$e->problems);
            }
        }

        if ($problems !== []) {
            throw new ConfigException($problems);
        }
    }

    /** Forgets every loaded object: a test's, after it changes the environment behind a digest. */
    public static function forget(): void
    {
        self::$loaded = [];
    }

    /** Whether $class is declared #[Config]. */
    public static function isConfig(string $class): bool
    {
        if (!isset(self::$isConfig[$class])) {
            self::$isConfig[$class] = class_exists($class) && new \ReflectionClass($class)->getAttributes(Config::class) !== [];
        }

        return self::$isConfig[$class];
    }

    /**
     * The class's keys, compiled once from its constructor.
     *
     * @param class-string $class
     * @return list<ConfigKey>
     * @throws \LogicException for a class that is no config class
     */
    public static function keys(string $class): array
    {
        return self::$keys[$class] ??= self::compile($class);
    }

    /**
     * The declaration rules over each class, and no variable read by two
     * keys: what `tinvar check` adds, without reading the environment.
     *
     * @param list<class-string> $classes
     * @return list<Violation>
     */
    public static function check(array $classes): array
    {
        $violations = [];
        $readBy = [];
        foreach ($classes as $class) {
            try {
                $keys = self::keys($class);
            } catch (\LogicException $e) {
                $violations[] = new Violation('config-must-compile', $class, $e->getMessage());

                continue;
            }

            foreach ($keys as $key) {
                foreach (array_filter([$key->variable, $key->fileVariable()]) as $variable) {
                    $where = "{$class}::\${$key->parameter}";
                    if (isset($readBy[$variable])) {
                        $violations[] = new Violation(
                            'config-variable-once',
                            $where,
                            "{$variable} is read by {$readBy[$variable]} as well. One key per variable, so its type and its default are said once.",
                        );

                        continue;
                    }
                    $readBy[$variable] = $where;
                }
            }
        }

        return $violations;
    }

    /**
     * What `tinvar config` lists: each key, where its value comes from, the
     * value where it is no secret, and what is wrong with it.
     *
     * @param class-string $class
     * @param array<string, string>|null $env
     * @return array{
     *     keys: list<array{key: ConfigKey, source: 'env'|'file'|'default'|'unset', value: string|null}>,
     *     problems: list<string>,
     * }
     */
    public static function inspect(string $class, ?array $env = null): array
    {
        $keys = self::keys($class);
        $raw = self::raw($keys, $env);

        $rows = [];
        foreach ($keys as $key) {
            $file = $key->fileVariable();
            // A secret has no default but null; a null default is unset.
            $default = $key->hasDefault && !$key->secret() ? self::shown($key, $class) : null;
            [$source, $value] = match (true) {
                $raw[$key->variable] !== null => ['env', $key->secret() ? null : $raw[$key->variable]],
                $file !== null && $raw[$file] !== null => ['file', null],
                $default !== null => ['default', $default],
                default => ['unset', null],
            };
            $rows[] = ['key' => $key, 'source' => $source, 'value' => $value];
        }

        return ['keys' => $rows, 'problems' => self::build($class, $keys, $raw)[1]];
    }

    /**
     * @param list<ConfigKey> $keys
     * @param array<string, string>|null $env
     * @return array<string, string|null> variable => its value, null when unset or empty
     */
    private static function raw(array $keys, ?array $env): array
    {
        $raw = [];
        foreach ($keys as $key) {
            foreach (array_filter([$key->variable, $key->fileVariable()]) as $variable) {
                $value = $env === null ? getenv($variable) : ($env[$variable] ?? false);
                $raw[$variable] = \is_string($value) && $value !== '' ? $value : null;
            }
        }

        return $raw;
    }

    /**
     * The object, or null and why not.
     *
     * @param class-string $class
     * @param list<ConfigKey> $keys
     * @param array<string, string|null> $raw
     * @return array{0: object|null, 1: list<string>}
     */
    private static function build(string $class, array $keys, array $raw): array
    {
        $arguments = [];
        $problems = [];
        foreach ($keys as $key) {
            try {
                $given = self::given($key, $raw);
                if ($given === null) {
                    if ($key->required) {
                        $problems[] = ($key->file ? "{$key->variable} (or {$key->fileVariable()})" : $key->variable) . ' is required, and is not set.';
                    } elseif (!$key->hasDefault) {
                        $arguments[$key->parameter] = null;
                    }

                    continue;
                }

                $arguments[$key->parameter] = self::parse($key, $given);
            } catch (ConfigException $e) {
                array_push($problems, ...$e->problems);
            }
        }

        if ($problems !== []) {
            return [null, $problems];
        }

        try {
            return [new $class(...$arguments), []];
        } catch (ConfigException $e) {
            return [null, $e->problems];
        } catch (\Throwable $e) {
            // Not its message: it may quote the value it refused.
            return [null, [$class . ' refused its configuration with ' . $e::class . '; throw ConfigException::invalid() to name the variable and say why.']];
        }
    }

    /**
     * The key's text: its variable's, or its file's contents.
     *
     * @param array<string, string|null> $raw
     * @throws ConfigException
     */
    private static function given(ConfigKey $key, array $raw): ?string
    {
        $value = $raw[$key->variable];
        $fileVariable = $key->fileVariable();
        $path = $fileVariable === null ? null : $raw[$fileVariable];
        if ($path === null) {
            return $value;
        }

        if ($value !== null) {
            throw ConfigException::invalid($key->variable, "and {$fileVariable} are both set; set one");
        }
        if (!str_starts_with($path, '/')) {
            throw ConfigException::invalid((string) $fileVariable, 'must be an absolute path');
        }

        $contents = is_file($path) && is_readable($path) ? @file_get_contents($path, false, null, 0, self::MAX_FILE_BYTES + 1) : false;
        if (!\is_string($contents)) {
            throw ConfigException::invalid((string) $fileVariable, 'names a file this process cannot read');
        }
        if (\strlen($contents) > self::MAX_FILE_BYTES) {
            throw ConfigException::invalid((string) $fileVariable, 'names a file larger than ' . self::MAX_FILE_BYTES . ' bytes');
        }

        // One line ending, as `echo secret > file` leaves; nothing else is
        // trimmed, since a secret may be any bytes at all.
        $contents = preg_replace('/\r?\n\z/', '', $contents) ?? $contents;
        if ($contents === '') {
            throw ConfigException::invalid((string) $fileVariable, 'names an empty file');
        }

        return $contents;
    }

    /** @throws ConfigException */
    private static function parse(ConfigKey $key, #[\SensitiveParameter] string $given): mixed
    {
        switch ($key->type) {
            case 'string':
                return $given;

            case 'secret':
                return new Secret($given);

            case 'bool':
                return match ($given) {
                    '1' => true,
                    '0' => false,
                    default => throw ConfigException::invalid($key->variable, 'must be 1 or 0'),
                };

            case 'int':
                $int = preg_match('/^-?(0|[1-9][0-9]*)\z/', $given) === 1 ? filter_var($given, FILTER_VALIDATE_INT) : false;

                return \is_int($int) ? $int : throw ConfigException::invalid($key->variable, 'must be a whole number');

            case 'float':
                if (preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][-+]?[0-9]+)?\z/', $given) !== 1 || !is_finite((float) $given)) {
                    throw ConfigException::invalid($key->variable, 'must be a number');
                }

                return (float) $given;

            default:
                /** @var class-string<\BackedEnum> $enum */
                $enum = $key->enum;
                $backing = (string) new \ReflectionEnum($enum)->getBackingType();
                $case = $backing === 'int'
                    ? (preg_match('/^-?(0|[1-9][0-9]*)\z/', $given) === 1 ? $enum::tryFrom((int) $given) : null)
                    : $enum::tryFrom($given);

                return $case ?? throw ConfigException::invalid(
                    $key->variable,
                    'must be one of ' . implode(', ', array_map(static fn (\BackedEnum $c): string => (string) $c->value, $enum::cases())),
                );
        }
    }

    /**
     * A non-secret key's default, as the listing shows it.
     *
     * @param class-string $class
     */
    private static function shown(ConfigKey $key, string $class): ?string
    {
        foreach (new \ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() !== $key->parameter || !$parameter->isDefaultValueAvailable()) {
                continue;
            }

            $default = $parameter->getDefaultValue();

            return match (true) {
                $default === null => null,
                \is_bool($default) => $default ? '1' : '0',
                \is_scalar($default) => (string) $default,
                $default instanceof \BackedEnum => (string) $default->value,
                default => null,
            };
        }

        return null;
    }

    /**
     * @param class-string $class
     * @return list<ConfigKey>
     */
    private static function compile(string $class): array
    {
        if (!class_exists($class)) {
            throw new \LogicException("{$class} is not a class.");
        }

        $reflection = new \ReflectionClass($class);
        $attribute = $reflection->getAttributes(Config::class)[0] ?? null;
        if ($attribute === null) {
            throw new \LogicException("{$class} is not declared #[Config].");
        }
        $prefix = $attribute->newInstance()->prefix;

        if (!$reflection->isFinal() || !$reflection->isReadOnly()) {
            throw new \LogicException("{$class} must be a final readonly class: config is fixed at boot, and nothing changes it after.");
        }
        // Never anything the encoder writes or the mapper reads: config is
        // the server's, and none of it is sent to the renderer.
        if ($reflection->isSubclassOf(Data::class) || $reflection->implementsInterface(ProvidesData::class) || $reflection->implementsInterface(\JsonSerializable::class)) {
            throw new \LogicException("{$class} is config, and config is never Data, ProvidesData or JsonSerializable: nothing in it is sent to the renderer.");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getParameters() === []) {
            throw new \LogicException("{$class} declares no keys: each constructor parameter is one.");
        }
        if (!$constructor->isPublic()) {
            throw new \LogicException("{$class}'s constructor must be public, for Configs to fill it.");
        }

        $keys = [];
        $variables = [];
        foreach ($constructor->getParameters() as $parameter) {
            $key = self::compileKey($class, $prefix, $parameter);
            if (isset($variables[$key->variable])) {
                throw new \LogicException("{$class}::\${$key->parameter} reads {$key->variable}, as \${$variables[$key->variable]} does.");
            }
            $variables[$key->variable] = $key->parameter;
            $keys[] = $key;
        }

        return $keys;
    }

    /** @param class-string $class */
    private static function compileKey(string $class, string $prefix, \ReflectionParameter $parameter): ConfigKey
    {
        $name = $parameter->getName();
        $where = "{$class}::\${$name}";
        if ($parameter->isVariadic()) {
            throw new \LogicException("{$where} is variadic; a key is one value.");
        }

        $type = $parameter->getType();
        if (!$type instanceof \ReflectionNamedType) {
            throw new \LogicException("{$where} needs one type, or one type and null: string, int, float, bool, a backed enum or Secret.");
        }

        $typeName = $type->getName();
        $enum = null;
        $kind = match (true) {
            \in_array($typeName, ['string', 'int', 'float', 'bool'], true) => $typeName,
            $typeName === Secret::class => 'secret',
            enum_exists($typeName) && is_subclass_of($typeName, \BackedEnum::class) => 'enum',
            default => throw new \LogicException("{$where} is {$typeName}, which no variable can say: string, int, float, bool, a backed enum or Secret."),
        };
        if ($kind === 'enum') {
            /** @var class-string<\BackedEnum> $typeName */
            $enum = $typeName;
        }

        $sensitive = $parameter->getAttributes(\SensitiveParameter::class) !== [];
        if ($kind === 'secret' && !$sensitive) {
            throw new \LogicException("{$where} is a Secret: mark it #[\\SensitiveParameter], so no trace shows what it was built from.");
        }
        if ($sensitive && $kind !== 'secret') {
            throw new \LogicException("{$where} is #[\\SensitiveParameter]: type it Secret, which no dump, log or encoder shows.");
        }

        $hasDefault = $parameter->isDefaultValueAvailable();
        if ($kind === 'secret' && $hasDefault && $parameter->getDefaultValue() !== null) {
            throw new \LogicException("{$where} is a Secret with a default: a secret in the source is no secret. Make it required, or null when unset.");
        }

        $env = ($parameter->getAttributes(Env::class)[0] ?? null)?->newInstance();
        if ($env !== null && $env->file && $kind !== 'secret') {
            throw new \LogicException("{$where} reads a file, which only a Secret does.");
        }

        $variable = $env->name ?? $prefix . strtoupper((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $name));
        if (preg_match('/^[A-Z][A-Z0-9_]*\z/', $variable) !== 1) {
            throw new \LogicException("{$where} reads '{$variable}', which is not an environment variable name; name one with #[Env].");
        }

        return new ConfigKey(
            variable: $variable,
            parameter: $name,
            type: $kind,
            enum: $enum,
            nullable: $type->allowsNull(),
            required: !$hasDefault && !$type->allowsNull(),
            file: $env !== null && $env->file,
            hasDefault: $hasDefault,
        );
    }
}
