<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Tinvar\Config\Configs;
use Tinvar\Observability\Redactor;

/**
 * `tinvar config`: every key an application's #[Config] classes read, where
 * each one's value comes from, and whether this environment would boot
 * (ADR 0085).
 *
 *   SHOP_DATABASE               ?string   env       /srv/shop/shop.sqlite
 *   SHOP_WAREHOUSE_TOKEN        ?Secret   file      [secret]
 *   SHOP_LOG_LEVEL              string    default   info
 *
 * A secret's value is never shown, from the environment or from its file:
 * `[secret]` says it is set, and nothing says more. Every other value is
 * shown as the logs would write it, through the Redactor -- a URL with a
 * password in it, set where a Secret should have been, has the password cut
 * out. Exits 1 when a key is missing or malformed, with each problem named
 * as the boot would name it, so a deploy can run it first.
 */
final class ConfigCommand
{
    /** @var \Closure(string): void */
    private readonly \Closure $out;

    /** @var \Closure(string): void */
    private readonly \Closure $err;

    /**
     * @param list<class-string> $classes the application's #[Config] classes
     * @param array<string, string>|null $env in place of the process's: a test's
     * @param (\Closure(string): void)|null $out a line for standard output
     * @param (\Closure(string): void)|null $err a line for standard error
     */
    public function __construct(
        private readonly array $classes,
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

    /** @param list<string> $arguments what follows the command */
    public function run(array $arguments = []): int
    {
        if ($arguments !== []) {
            ($this->err)('usage: tinvar config');

            return BuildCommand::USAGE;
        }

        $redactor = new Redactor(traces: false);
        $problems = [];
        $sections = [];
        foreach ($this->classes as $class) {
            try {
                $inspected = Configs::inspect($class, $this->env);
            } catch (\LogicException $e) {
                $problems[] = $e->getMessage();

                continue;
            }

            $rows = [];
            foreach ($inspected['keys'] as $row) {
                $key = $row['key'];
                $value = match (true) {
                    $key->secret() && $row['source'] !== 'unset' => '[secret]',
                    $row['value'] === null => '',
                    // One line each: a value with a line break in it would
                    // otherwise print as a key of its own.
                    default => str_replace(["\r", "\n"], ['\r', '\n'], $redactor->text($row['value'])),
                };
                $rows[] = [$key->variable . ($key->required ? ' *' : ''), $key->describe(), $row['source'], $value];
            }
            $sections[$class] = $rows;
            array_push($problems, ...$inspected['problems']);
        }

        $widths = [0, 0, 0];
        foreach ($sections as $rows) {
            foreach ($rows as $row) {
                foreach ($widths as $i => $width) {
                    $widths[$i] = max($width, \strlen($row[$i]));
                }
            }
        }
        foreach ($sections as $class => $rows) {
            ($this->out)($class);
            foreach ($rows as $row) {
                ($this->out)(rtrim(\sprintf("  %-{$widths[0]}s  %-{$widths[1]}s  %-{$widths[2]}s  %s", ...$row)));
            }
        }

        if ($problems !== []) {
            ($this->err)('Configuration refused:');
            foreach ($problems as $problem) {
                ($this->err)('  - ' . $problem);
            }

            return 1;
        }

        ($this->out)('ok: every key is set or has a default, and parses (* marks a required key)');

        return 0;
    }
}
