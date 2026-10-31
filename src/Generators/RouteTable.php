<?php

declare(strict_types=1);

namespace Tinvar\Generators;

/**
 * The renderer's route table -- `const ROUTES` in an application's rsc
 * entry, each view PHP may name with its page component and the layouts
 * around it -- and the one edit make:page makes to it: the new view's
 * entry, and the import of its component.
 *
 *   import NotesShowPage from '../pages/notes/show';
 *   ...
 *   'notes/show': { Page: NotesShowPage as Page, layouts: ['app'] },
 *
 * It is made only where the table reads
 * exactly as the table make:page expects, and refused otherwise: one
 * `const ROUTES` whose object literal is opened at the end of its line and
 * closed on a line of its own, and holds nothing but comments and entries
 * written one to a line as above. A table written any other way -- built
 * by a function, spread from another, an entry over two lines -- is the
 * application's, and this does not guess at it.
 *
 * A view already in the table is left. A layout the table's type does not
 * list (`layouts: readonly 'app'[]`) is refused rather than widened: which
 * component draws it is the application's to say.
 */
final class RouteTable
{
    private const string ENTRY = "/^(\\s*)'([a-z0-9][a-z0-9\\/_-]*)': \\{ Page: ([A-Za-z_$][A-Za-z0-9_$]*) as Page, layouts: \\[((?:'[a-z0-9\\/_-]+'(?:, )?)*)\\] \\},(\\s*\\/\\/.*)?$/";

    /**
     * Adds $view to the table in $file, drawn by the component at $component
     * (a path without its extension) inside $layouts, outermost first.
     *
     * @param list<string> $layouts
     * @return bool true where it was added, false where the view was there already
     * @throws RegistrationRefused
     */
    public static function add(string $file, string $view, string $component, array $layouts): bool
    {
        // Both go into the file between quotes: a view is a view's name,
        // and a component a path with nothing in it that could end its
        // string, whatever directory the project was made in.
        if (preg_match('/^[a-z0-9][a-z0-9\/_-]*\z/', $view) !== 1) {
            throw new RegistrationRefused("'{$view}' is not a view name the table takes");
        }
        if (preg_match('/^\.{1,2}\/[A-Za-z0-9_.\/-]+\z/', $component) !== 1 || str_contains($component, '//')) {
            throw new RegistrationRefused('the view\'s path from it holds characters an import is not written with');
        }

        $source = ProjectFile::read($file);
        $lines = explode("\n", $source);

        $declared = preg_grep('/^(?:export\s+)?const ROUTES\b/', $lines) ?: [];
        if (\count($declared) !== 1) {
            throw new RegistrationRefused($declared === [] ? 'it declares no const ROUTES' : 'it declares ROUTES more than once');
        }
        $open = (int) array_key_first($declared);
        if (!str_ends_with(rtrim($lines[$open]), '= {')) {
            throw new RegistrationRefused('its ROUTES is not an object literal opened on the line that declares it');
        }

        // Which layout keys the table's type allows, where it says.
        $allowed = null;
        if (preg_match('/layouts:\s*readonly\s*\(?((?:\s*\'[^\']+\'\s*\|?)+)\)?\[\]/', $lines[$open], $type) === 1) {
            preg_match_all("/'([^']+)'/", $type[1], $keys);
            $allowed = $keys[1];
        }

        $close = null;
        $last = null;
        $indent = '  ';
        for ($i = $open + 1, $count = \count($lines); $i < $count; $i++) {
            $line = $lines[$i];
            if (rtrim($line) === '};') {
                $close = $i;

                break;
            }
            if (trim($line) === '' || preg_match('/^\s*\/\/.*$/', $line) === 1) {
                continue;
            }
            if (preg_match(self::ENTRY, $line, $entry) !== 1) {
                throw new RegistrationRefused('its ROUTES holds a line that is not one entry written as make:page writes one');
            }
            if ($entry[2] === $view) {
                return false;
            }
            $last = $i;
            $indent = $entry[1];
        }
        if ($close === null) {
            throw new RegistrationRefused('its ROUTES is not closed by a line of its own');
        }

        foreach ($layouts as $layout) {
            if (preg_match('/^[a-z0-9][a-z0-9\/_-]*\z/', $layout) !== 1 || ($allowed !== null && !\in_array($layout, $allowed, true))) {
                throw new RegistrationRefused("its ROUTES type does not list the layout '{$layout}'");
            }
        }

        $identifier = self::identifier($view, $source);
        $entry = \sprintf(
            "%s'%s': { Page: %s as Page, layouts: [%s] },",
            $indent,
            $view,
            $identifier,
            implode(', ', array_map(static fn (string $layout): string => "'{$layout}'", $layouts)),
        );
        array_splice($lines, $last === null ? $close : $last + 1, 0, [$entry]);

        // The import, after the file's last: every import is at the top.
        $import = "import {$identifier} from '{$component}';";
        $lastImport = null;
        foreach ($lines as $i => $line) {
            if ($i >= $open) {
                break;
            }
            if (str_starts_with($line, 'import ')) {
                if (!str_ends_with(rtrim($line), ';')) {
                    throw new RegistrationRefused('it has an import over more than one line');
                }
                $lastImport = $i;
            }
        }
        if ($lastImport === null) {
            throw new RegistrationRefused('it has no imports to add one beside');
        }
        array_splice($lines, $lastImport + 1, 0, [$import]);

        ProjectFile::write($file, implode("\n", $lines));

        return true;
    }

    /**
     * The import path of $target from $file's directory: `./` or `../`,
     * slashes, no extension.
     */
    public static function importPath(string $file, string $target): string
    {
        $from = explode('/', trim(\dirname($file), '/'));
        $to = explode('/', trim($target, '/'));
        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }
        $path = str_repeat('../', \count($from)) . implode('/', $to);

        return str_starts_with($path, '../') ? $path : "./{$path}";
    }

    /** NotesShowPage for notes/show: one the file does not use yet. */
    private static function identifier(string $view, string $source): string
    {
        $base = implode('', array_map(
            static fn (string $part): string => ucfirst($part),
            preg_split('/[\/_-]+/', $view) ?: [],
        )) . 'Page';
        $base = preg_match('/^[A-Za-z_$]/', $base) === 1 ? $base : "View{$base}";
        for ($n = 1, $name = $base; preg_match('/(?<![A-Za-z0-9_$])' . preg_quote($name, '/') . '(?![A-Za-z0-9_$])/', $source) === 1; $n++) {
            $name = $base . ($n + 1);
        }

        return $name;
    }
}
