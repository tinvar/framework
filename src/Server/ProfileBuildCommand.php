<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Tinvar\Support\BuildProfile;
use Tinvar\Support\DevMode;

/**
 * `tinvar build`: an application's Vite build, made for a profile.
 *
 *   tinvar build [--profile=production|test] [--app=DIR] [--out=DIR]
 *
 * tinvar-spec.md, "Dev and test tooling": "Only `tinvar dev` and
 * `--profile=test` include them" -- the browser test helpers. The build is
 * Vite's; this runs it with TINVAR_PROFILE set, and the application's
 * buildProfile() plugin records the profile in the build, which the server
 * holds its own profile to (BuildProfile, ADR 0066). `--out` puts it in a
 * directory of its own, as a test build beside a production one.
 *
 * A test build is refused where TINVAR_ENV, APP_ENV or NODE_ENV says
 * production. Building one elsewhere is harmless by itself -- serving it
 * needs the test profile, which needs an environment that says it is not
 * production -- so an unset environment is not refused here as it is when
 * serving.
 */
final class ProfileBuildCommand
{
    public const int USAGE = 64;

    private const array OPTIONS = ['profile', 'app', 'out'];

    /** @var \Closure(list<string>, string, array<string, string>): int */
    private readonly \Closure $run;

    /** @var \Closure(string): void */
    private readonly \Closure $err;

    /**
     * @param (\Closure(list<string>, string, array<string, string>): int)|null $run runs a
     *        command in a directory with an environment and returns its exit status; a test's
     * @param array<string, string>|null $env the environment; the process's own by default
     */
    public function __construct(
        private readonly string $workingDirectory,
        ?\Closure $run = null,
        ?\Closure $err = null,
        private readonly ?array $env = null,
    ) {
        $this->run = $run ?? static function (array $command, string $cwd, array $env): int {
            $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR], $pipes, $cwd, $env);
            if (!\is_resource($process)) {
                throw new \RuntimeException('Could not start ' . $command[0] . '.');
            }

            return proc_close($process);
        };
        $this->err = $err ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            [$command, $cwd, $env] = $this->plan($arguments);
        } catch (\InvalidArgumentException $e) {
            ($this->err)($e->getMessage());

            return self::USAGE;
        } catch (\RuntimeException $e) {
            ($this->err)($e->getMessage());

            return 1;
        }

        return ($this->run)($command, $cwd, $env);
    }

    /**
     * What would run: the command, where, and with what environment.
     *
     * @param list<string> $arguments
     * @return array{list<string>, string, array<string, string>}
     */
    public function plan(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z]+)=(.+)\z/s', $argument, $m) !== 1 || !\in_array($m[1], self::OPTIONS, true)) {
                throw new \InvalidArgumentException(
                    "Unknown argument '{$argument}'. tinvar build [--profile=production|test] [--app=DIR] [--out=DIR]",
                );
            }
            $options[$m[1]] = $m[2];
        }

        $env = $this->env ?? array_filter(getenv(), \is_string(...));
        $profile = strtolower($options['profile'] ?? BuildProfile::PRODUCTION);
        if (!\in_array($profile, BuildProfile::PROFILES, true)) {
            throw new \InvalidArgumentException("--profile={$profile} is not a profile: it is production or test.");
        }
        if ($profile === BuildProfile::TEST) {
            $marker = DevMode::productionMarker($env);
            if ($marker !== null) {
                throw new \RuntimeException(
                    "tinvar build --profile=test is refused: {$marker}=production. A test build is served with the "
                    . 'browser test endpoint, which never runs in production.',
                );
            }
        }

        $given = $options['app'] ?? null;
        $candidate = $given === null ? $this->workingDirectory : (str_starts_with($given, '/') ? $given : "{$this->workingDirectory}/{$given}");
        $app = realpath($candidate);
        if ($app === false || !is_dir($app) || !is_file("{$app}/package.json")) {
            throw new \RuntimeException("No app to build at {$candidate}: run from an app directory, or pass --app=DIR.");
        }

        // What the build reads, and nothing it should not: the profile, and
        // where it goes -- or the application's own dist/ when no --out says,
        // whatever this shell's TINVAR_BUILD_DIR was left at.
        $env = array_diff_key($env, [BuildProfile::BUILD_DIR_ENV => true]);
        $env[BuildProfile::ENV] = $profile;
        $out = $options['out'] ?? null;
        if ($out !== null) {
            $env[BuildProfile::BUILD_DIR_ENV] = str_starts_with($out, '/') ? $out : "{$this->workingDirectory}/{$out}";
        }

        return [['npx', 'vite', 'build'], $app, $env];
    }
}
