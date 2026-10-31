<?php

declare(strict_types=1);

namespace Tinvar\Scenarios;

/**
 * `tinvar scenario:seed`: one of an application's `#[Scenario]`s, seeded
 * from the command line -- for `tinvar bench`, which signs in only as the
 * users a scenario made (ADR 0090).
 *
 *   tinvar scenario:seed <name> [--input=JSON] [--credentials=FILE]
 *
 * Refused under the production profile, as every seeding of a scenario is
 * (Scenarios::seed()). The users' sign-ins, when the scenario made any, are
 * written to FILE alone: absolute, new -- never one that is there already,
 * which another could have made to read them from -- and readable by this
 * user alone from its first byte. They are never printed.
 */
final class ScenarioSeedCommand
{
    public const int USAGE = 64;

    public const int REFUSED = 65;

    private const string SYNOPSIS = 'tinvar scenario:seed <name> [--input=JSON] [--credentials=FILE]';

    /** @var \Closure(string): void */
    private readonly \Closure $out;

    /** @var \Closure(string): void */
    private readonly \Closure $err;

    /**
     * @param \Closure(): Scenarios $scenarios the application's, against the database it names
     * @param array<string, string>|null $env
     */
    public function __construct(
        private readonly \Closure $scenarios,
        private readonly ?array $env = null,
        ?\Closure $out = null,
        ?\Closure $err = null,
    ) {
        $this->out = $out ?? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
        };
        $this->err = $err ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $name = null;
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--(input|credentials)=(.+)\z/s', $argument, $m) === 1) {
                $options[$m[1]] = $m[2];
            } elseif ($name === null && !str_starts_with($argument, '-')) {
                $name = $argument;
            } else {
                ($this->err)("Unknown argument '{$argument}'. " . self::SYNOPSIS);

                return self::USAGE;
            }
        }
        if ($name === null) {
            ($this->err)(self::SYNOPSIS);

            return self::USAGE;
        }

        $input = [];
        if (isset($options['input'])) {
            $decoded = json_decode($options['input'], true);
            if (!\is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                ($this->err)('--input is a JSON object.');

                return self::USAGE;
            }
            /** @var array<string, mixed> $decoded */
            $input = $decoded;
        }

        $file = $options['credentials'] ?? null;
        if ($file !== null && (!str_starts_with($file, '/') || file_exists($file) || is_link($file))) {
            ($this->err)("--credentials names {$file}: a new file, named absolutely, which nothing has made yet.");

            return self::USAGE;
        }

        // Made before the users are: a file that cannot be made once they
        // exist leaves them with passwords nobody holds.
        $handle = null;
        if ($file !== null) {
            $handle = self::createPrivately($file);
            if ($handle === null) {
                ($this->err)("Cannot create {$file} for the scenario's sign-ins; nothing was seeded.");

                return self::REFUSED;
            }
        }

        try {
            $seeded = ($this->scenarios)()->seed($name, $input, $this->env);
        } catch (\LogicException|\InvalidArgumentException|\RuntimeException $e) {
            self::discard($handle, $file);
            ($this->err)($e->getMessage());

            return self::REFUSED;
        } catch (\Throwable $e) {
            self::discard($handle, $file);

            throw $e;
        }

        if ($handle !== null) {
            $contents = json_encode($seeded->credentials, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            $written = fwrite($handle, $contents) === \strlen($contents) && fflush($handle);
            $written = fclose($handle) && $written;
            if (!$written) {
                @unlink($file);
                ($this->err)(\sprintf(
                    "Seeded '%s', but its sign-ins could not be written to %s: seed it again into a fresh database.",
                    $name,
                    $file,
                ));

                return self::REFUSED;
            }
        }

        ($this->out)(\sprintf(
            "Seeded '%s': %d user%s%s.",
            $name,
            \count($seeded->users),
            \count($seeded->users) === 1 ? '' : 's',
            $file === null ? '' : ', their sign-ins in ' . $file,
        ));

        return 0;
    }

    /**
     * Created exclusively, under a 0077 umask: never readable by anyone
     * else, not for an instant.
     *
     * @return resource|null
     */
    private static function createPrivately(string $file)
    {
        $previous = umask(0077);
        // A file that cannot be made is an answer, said by the caller: the
        // warning fopen() raises beside it -- through @, to any error
        // handler installed -- is held back.
        set_error_handler(static fn (): bool => true);
        try {
            $handle = fopen($file, 'x');
        } finally {
            restore_error_handler();
            umask($previous);
        }

        return $handle === false ? null : $handle;
    }

    /**
     * The file made for sign-ins that will not come: closed and removed.
     *
     * @param resource|null $handle
     */
    private static function discard($handle, ?string $file): void
    {
        if ($handle !== null) {
            fclose($handle);
        }
        if ($file !== null && $handle !== null) {
            @unlink($file);
        }
    }
}
