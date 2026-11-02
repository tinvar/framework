<?php

declare(strict_types=1);

namespace Tinvar\Console;

use Tinvar\Auth\Violation;

/**
 * `tinvar check --json`: what the check found, for an editor to put in its
 * problems list (ADR 0158) -- each problem with the file and line of the
 * class, method or property it names, where it names one.
 *
 *     {"format": 1, "passed": false, "sections": [
 *         {"heading": "Build rules failed:", "problems": [
 *             {"message": "...", "rule": "...", "where": "App\\Http\\Queries\\Recent", "file": "/abs/app/Http/Queries/Recent.php", "line": 12}
 *         ]}
 *     ]}
 *
 * A section that passed has no problems and its lines under "passed". The
 * location is read off the class by reflection, from the classes the check
 * has already loaded; a problem naming no class it can find has none, and
 * an editor shows it against the app as a whole.
 */
final class CheckReport
{
    public const int FORMAT = 1;

    /**
     * @param list<array{0: string, 1: list<string|Violation|\Stringable>, 2: list<string>}> $results heading, problems, the lines it prints when it passed
     */
    public static function json(array $results): string
    {
        $sections = [];
        $failed = false;
        foreach ($results as [$heading, $problems, $passed]) {
            $failed = $failed || $problems !== [];
            $sections[] = [
                'heading' => $heading,
                'problems' => array_map(self::problem(...), $problems),
                'passed' => $problems === [] ? $passed : [],
            ];
        }

        return json_encode(
            ['format' => self::FORMAT, 'passed' => !$failed, 'sections' => $sections],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        ) . "\n";
    }

    /**
     * One problem: its message, its rule and where it is for a build-rule
     * violation, and the file and line of what it names.
     *
     * @return array{message: string, rule: string|null, where: string|null, file: string|null, line: int|null}
     */
    public static function problem(string|Violation|\Stringable $problem): array
    {
        $rule = $problem instanceof Violation ? $problem->rule : null;
        $where = $problem instanceof Violation ? $problem->where : null;
        $message = $problem instanceof Violation ? $problem->message : (string) $problem;
        [$file, $line] = self::locate($where ?? '') ?? self::locate($message) ?? [null, null];

        return ['message' => $message, 'rule' => $rule, 'where' => $where, 'file' => $file, 'line' => $line];
    }

    /**
     * The file and line of the first class named in $text that is loaded or
     * autoloadable -- of its method or property, where `::name` follows it.
     *
     * @return array{string, int}|null
     */
    public static function locate(string $text): ?array
    {
        if (preg_match_all('/((?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*)(?:::\$?([A-Za-z_][A-Za-z0-9_]*))?/', $text, $matches, PREG_SET_ORDER) === false) {
            return null;
        }
        foreach ($matches as $match) {
            $class = ltrim($match[1], '\\');
            try {
                if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
                    continue;
                }
            } catch (\Throwable) {
                continue;   // an autoloader that throws on a name it does not know
            }
            $reflection = new \ReflectionClass($class);
            $file = $reflection->getFileName();
            if ($file === false) {
                continue;
            }
            $member = $match[2] ?? '';
            $line = $reflection->getStartLine();
            if ($member !== '' && $reflection->hasMethod($member)) {
                $line = $reflection->getMethod($member)->getStartLine();
            } elseif ($member !== '' && $reflection->hasProperty($member)) {
                $line = self::propertyLine($reflection, $member) ?? $line;
            }

            return [$file, $line === false ? 1 : $line];
        }

        return null;
    }

    /**
     * A property's line: reflection gives none, so the line of its
     * declaration in the class's source, or a promoted one's in the
     * constructor.
     *
     * @param \ReflectionClass<object> $class
     */
    private static function propertyLine(\ReflectionClass $class, string $property): ?int
    {
        $file = $class->getFileName();
        $start = $class->getStartLine();
        $end = $class->getEndLine();
        if ($file === false || $start === false || $end === false) {
            return null;
        }
        $lines = @file($file) ?: [];
        for ($number = $start; $number <= $end && $number <= \count($lines); $number++) {
            if (preg_match('/\$' . preg_quote($property, '/') . '\b/', $lines[$number - 1]) === 1) {
                return $number;
            }
        }

        return null;
    }
}
