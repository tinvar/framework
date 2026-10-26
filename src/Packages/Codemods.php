<?php

declare(strict_types=1);

namespace Tinvar\Packages;

/**
 * Package codemods: scripts an enabled package ships to rewrite the app
 * that uses it, run only when asked, in a sandbox, into a patch.
 *
 * "Package codemods run only with --allow-package-codemods, sandboxed, and
 * any diff touching Authorize, Public or NoAuthorization is flagged for
 * review" (tinvar-spec.md). A package declares them in its metadata --
 * `"extra": {"tinvar": {"codemods": ["codemods/v2.php"]}}`, or `tinvar.codemods`
 * in a package.json -- and each is a PHP script called with one argument,
 * the directory of the app to rewrite.
 *
 * Only packages that pass the supply-chain check run: allowed, and the very
 * files tinvar.lock pinned. Each script then runs in a process of its own,
 * inside two sandboxes:
 *
 *   - The operating system's: no network, and no writes outside the run's
 *     own directory -- sandbox-exec on macOS, bubblewrap (`bwrap`) on Linux.
 *     Where neither is installed, codemods do not run: PHP's own limits
 *     cannot stand alone, since a static PHP build carries PDO, Redis and
 *     SOAP clients that open connections no ini setting turns off (PHP 8.5
 *     removed disable_classes).
 *   - PHP's: a copy of the package and a copy of the app's tracked files --
 *     no database, no build, no .env, nothing git ignores -- in a fresh
 *     directory `open_basedir` confines it to; no php.ini, FFI off, URL
 *     wrappers off, and every function that starts a process, opens a
 *     socket, resolves a name, sends mail, or changes the environment or an
 *     ini setting disabled.
 *
 * Its environment is PATH alone, so no secret the shell holds reaches it,
 * and it is killed at a time limit.
 *
 * The real app is never touched. What the scripts changed comes back as a
 * unified diff, with these flagged:
 *
 *   - any PHP file added or deleted;
 *   - any file whose name a patch tool could read as another's, which is
 *     left out of the patch (patchable());
 *   - in a PHP file, every changed line of an attribute block, a `use`, a
 *     namespace declaration, or a constant an attribute could name (a
 *     `const`, an enum case, a define()), whatever it names, and every
 *     class or method whose authorization attributes, parent or traits
 *     differ once names are resolved (AuthorizationMap); in one that does
 *     not parse, that names define() or call_user_func(), or that holds a
 *     carriage return ending no line, every changed line;
 *   - whenever anything changed, every authorization attribute in the app
 *     whose arguments rest on a global constant, which any code can
 *     declare where nothing shows it, and every class declared in more
 *     than one file (unverifiable());
 *   - in any file, every changed line that names an authorization
 *     attribute.
 */
final class Codemods
{
    /**
     * What the flag is about: the attributes declarative authorization is
     * made of, in any case, as PHP reads class names -- all but `Public`,
     * which in any other case is the keyword half the lines of a class start
     * with. AuthorizationMap reads it as an attribute in any case.
     */
    private const string AUTHORIZATION = '/\b(?i:Authorize|PublicAccess|NoAuthorization)\b|\bPublic\b/';

    private const array DISABLED = [
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'pcntl_fork',
        'pcntl_signal', 'posix_kill', 'posix_mkfifo', 'posix_setuid', 'posix_setgid', 'proc_nice',
        'putenv', 'dl', 'ini_set', 'ini_alter', 'ini_restore', 'set_include_path',
        'mail', 'mb_send_mail', 'error_log', 'openlog', 'syslog', 'link', 'symlink',
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server', 'stream_socket_pair',
        'socket_create', 'socket_create_listen', 'socket_create_pair', 'socket_connect',
        'socket_addrinfo_connect', 'socket_addrinfo_bind', 'curl_init', 'curl_exec', 'curl_multi_init',
        'curl_multi_exec', 'curl_share_init', 'ftp_connect', 'ftp_ssl_connect',
        'gethostbyname', 'gethostbynamel', 'gethostbyaddr', 'dns_get_record', 'dns_get_mx', 'getmxrr',
        'dns_check_record', 'checkdnsrr', 'get_headers', 'imap_open', 'apache_setenv',
        'pg_connect', 'pg_pconnect', 'mysqli_connect', 'ldap_connect', 'pcntl_rfork', 'pcntl_forkx',
    ];

    public function __construct(
        private readonly SupplyChain $supplyChain,
        private readonly int $timeout = 60,
    ) {}

    /**
     * Every codemod the enabled packages declare.
     *
     * @return list<array{package: string, script: string, directory: string}>
     */
    public function declared(): array
    {
        $codemods = [];
        foreach ($this->supplyChain->enabled() as $package) {
            $scripts = $package->metadata['codemods'] ?? [];
            if (!\is_array($scripts) || !array_is_list($scripts)) {
                throw new \RuntimeException("{$package->name} declares codemods that are not a list of scripts.");
            }
            foreach ($scripts as $script) {
                if (!\is_string($script)
                    || !str_ends_with($script, '.php')
                    || str_starts_with($script, '/')
                    || str_contains($script, '\\')
                    || preg_match('#(^|/)\.\.?(/|$)#', $script) === 1
                    || $package->directory === null
                    || !is_file($package->directory . '/' . $script)
                    || is_link($package->directory . '/' . $script)) {
                    throw new \RuntimeException(
                        "{$package->name} declares a codemod that is not a PHP file inside it: " . json_encode($script) . '.',
                    );
                }
                $codemods[] = ['package' => $package->name, 'script' => $script, 'directory' => $package->directory];
            }
        }

        return $codemods;
    }

    /**
     * @param bool $allowed whether --allow-package-codemods was given
     * @param string|null $only one package's codemods alone
     */
    public function run(string $app, bool $allowed, ?string $only = null): CodemodResult
    {
        if (!$allowed) {
            throw new \RuntimeException(
                'Package codemods run only with --allow-package-codemods: they are code from a dependency, run against yours.',
            );
        }

        $app = realpath($app) ?: throw new \RuntimeException("{$app} is not a directory.");
        $files = self::trackedFiles($app);
        $codemods = array_values(array_filter(
            $this->declared(),
            static fn (array $codemod): bool => $only === null || $codemod['package'] === $only,
        ));

        $sandbox = self::temporaryDirectory();
        try {
            $current = $sandbox . '/app-0';
            self::copyFiles($app, $files, $current);

            $ran = [];
            $failed = [];
            foreach ($codemods as $index => $codemod) {
                $run = $sandbox . '/run-' . ($index + 1);
                self::copyTree($codemod['directory'], $run . '/package');
                self::copyTree($current, $run . '/app');
                mkdir($run . '/tmp', 0o700);

                [$status, $output] = $this->sandboxed($run, $run . '/package/' . $codemod['script']);
                $label = self::printable("{$codemod['package']} {$codemod['script']}");
                if ($status !== 0) {
                    $failed[] = "{$label}: " . ($status === 124 ? "killed after {$this->timeout}s" : "exited {$status}")
                        . ($output === '' ? '' : ': ' . self::printable(trim(substr($output, 0, 2000)), lines: true));

                    break;
                }
                $ran[] = $label;
                $current = $run . '/app';
            }

            [$patch, $flagged] = self::diff($app, $files, $current);

            return new CodemodResult($patch, array_map(self::printable(...), $flagged), $ran, $failed);
        } finally {
            self::remove($sandbox);
        }
    }

    /** @return array{int, string} exit status (124 when killed) and what it printed */
    private function sandboxed(string $root, string $script): array
    {
        // A trailing slash, or open_basedir also admits /tmp/run-1x.
        $base = $root . '/';
        $command = [
            ...self::confinement($root),
            \PHP_BINARY, '-n',
            '-d', 'open_basedir=' . $base,
            '-d', 'disable_functions=' . implode(',', self::DISABLED),
            '-d', 'ffi.enable=0',
            '-d', 'allow_url_fopen=0',
            '-d', 'allow_url_include=0',
            '-d', 'auto_prepend_file=',
            '-d', 'auto_append_file=',
            '-d', 'memory_limit=256M',
            '-d', 'max_execution_time=' . $this->timeout,
            '-d', 'sys_temp_dir=' . $root . '/tmp',
            '-d', 'upload_tmp_dir=' . $root . '/tmp',
            '-d', 'display_errors=stderr',
            '-d', 'log_errors=0',
            $script,
            $root . '/app',
        ];

        return self::process($command, $root . '/app', ['PATH' => '/usr/bin:/bin', 'HOME' => $root . '/tmp', 'TMPDIR' => $root . '/tmp'], $this->timeout);
    }

    /**
     * The operating system's sandbox around the PHP process: no network,
     * and writes only under $root. Refuses where there is none.
     *
     * @return list<string> the command to put in front of PHP's
     */
    private static function confinement(string $root): array
    {
        if (preg_match('/["\\\\\x00-\x1f]/', $root) === 1) {
            throw new \RuntimeException("Cannot sandbox a run under {$root}.");
        }

        if (\PHP_OS_FAMILY === 'Darwin' && is_executable('/usr/bin/sandbox-exec')) {
            $profile = implode('', [
                '(version 1)',
                '(allow default)',
                '(deny network*)',
                '(deny process-fork)',
                '(deny file-write*)',
                '(allow file-write* (subpath "' . $root . '"))',
            ]);

            return ['/usr/bin/sandbox-exec', '-p', $profile];
        }

        if (\PHP_OS_FAMILY === 'Linux') {
            foreach (['/usr/bin/bwrap', '/bin/bwrap', '/usr/local/bin/bwrap'] as $bwrap) {
                if (is_executable($bwrap)) {
                    return [
                        $bwrap, '--ro-bind', '/', '/', '--dev', '/dev', '--bind', $root, $root,
                        '--unshare-all', '--die-with-parent', '--new-session', '--',
                    ];
                }
            }
        }

        throw new \RuntimeException(
            'No operating-system sandbox to run codemods in: install bubblewrap (bwrap) on Linux; macOS has sandbox-exec.',
        );
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{int, string}
     */
    private static function process(array $command, string $cwd, array $env, int $timeout, int $limit = 1 << 20): array
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Cannot start ' . $command[0] . '.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + $timeout;
        $killed = false;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            @stream_select($read, $write, $except, 0, 100_000);
            foreach ([$pipes[1], $pipes[2]] as $pipe) {
                $chunk = fread($pipe, 65536);
                if (\is_string($chunk) && \strlen($output) < $limit) {
                    $output .= substr($chunk, 0, $limit - \strlen($output));
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                $killed = true;

                break;
            }
        }
        foreach ([$pipes[1], $pipes[2]] as $pipe) {
            $rest = stream_get_contents($pipe);
            if (\is_string($rest) && \strlen($output) < $limit) {
                $output .= substr($rest, 0, $limit - \strlen($output));
            }
            fclose($pipe);
        }
        $code = proc_close($process);
        $exit = $status['running'] ? $code : $status['exitcode'];

        return [$killed ? 124 : $exit, $output];
    }

    /** @return list<string> paths relative to $app */
    private static function trackedFiles(string $app): array
    {
        [$status, $output] = self::process(['git', '-C', $app, 'ls-files', '-z', '--', '.'], $app, ['PATH' => (string) getenv('PATH')], 60, 64 << 20);
        if ($status !== 0) {
            throw new \RuntimeException("{$app} is not in a git repository: codemods run over tracked files only.");
        }

        return array_values(array_filter(
            explode("\0", $output),
            static fn (string $file): bool => $file !== '' && is_file($app . '/' . $file) && !is_link($app . '/' . $file),
        ));
    }

    /** @param list<string> $files */
    private static function copyFiles(string $from, array $files, string $to): void
    {
        foreach ($files as $file) {
            $target = $to . '/' . $file;
            if (!is_dir(\dirname($target))) {
                mkdir(\dirname($target), 0o700, true);
            }
            copy($from . '/' . $file, $target) ?: throw new \RuntimeException("Cannot copy {$file}.");
        }
        if (!is_dir($to)) {
            mkdir($to, 0o700, true);
        }
    }

    /** Regular files and directories only: a link is not followed out of the tree. */
    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0o700, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || is_link($from . '/' . $entry)) {
                continue;
            }
            if (is_dir($from . '/' . $entry)) {
                self::copyTree($from . '/' . $entry, $to . '/' . $entry);
            } else {
                copy($from . '/' . $entry, $to . '/' . $entry) ?: throw new \RuntimeException("Cannot copy {$entry}.");
            }
        }
    }

    /** @return list<string> regular files under $root, relative to it */
    private static function filesUnder(string $root, string $prefix = ''): array
    {
        $files = [];
        foreach (scandir($root . ($prefix === '' ? '' : '/' . $prefix)) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
            $path = $root . '/' . $relative;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $files = [...$files, ...self::filesUnder($root, $relative)];
            } else {
                $files[] = $relative;
            }
        }

        return $files;
    }

    /**
     * @param list<string> $files the tracked files the sandbox started from
     * @return array{string, list<string>}
     */
    private static function diff(string $app, array $files, string $result): array
    {
        $after = self::filesUnder($result);
        $paths = array_values(array_unique([...$files, ...$after]));
        sort($paths, \SORT_STRING);

        $patch = '';
        $flagged = [];
        foreach ($paths as $path) {
            $old = \in_array($path, $files, true) ? $app . '/' . $path : null;
            $new = \in_array($path, $after, true) ? $result . '/' . $path : null;
            if ($old !== null && $new !== null && hash_file('sha256', $old) === hash_file('sha256', $new)) {
                continue;
            }
            // diff writes a name into the patch's header as it is, and the
            // codemod chooses its files' names. One a patch tool could read
            // as another file is left out, flagged, so the flags below are
            // always about the file the patch changes.
            if (!self::patchable($path)) {
                $flagged[] = json_encode($path, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE)
                    . ':1: left out of the patch: patch or git apply could read another file\'s name in it';

                continue;
            }

            [$status, $hunk] = self::process(
                ['diff', '-u', '--label', 'a/' . $path, '--label', 'b/' . $path, $old ?? '/dev/null', $new ?? '/dev/null'],
                $result,
                ['PATH' => '/usr/bin:/bin'],
                60,
                64 << 20,
            );
            if ($status > 1) {
                throw new \RuntimeException("Cannot diff {$path}.");
            }
            $patch .= $hunk;

            $before = $old === null ? null : (string) file_get_contents($old);
            $now = $new === null ? null : (string) file_get_contents($new);
            // A file PHP could run is read by its tokens. A null map is one
            // that does not parse, which nothing can be compared against.
            $was = $is = AuthorizationMap::empty();
            $whole = false;
            if (self::isPhp($path, $before) || self::isPhp($path, $now)) {
                $whole = $before === null || $now === null;
                if ($whole) {
                    $flagged[] = "{$path}:1: " . ($before === null ? 'new' : 'deleted') . ' PHP file';
                }
                $was = self::read($before);
                $is = self::read($now);
                if (!$whole && ($was === null || $is === null)) {
                    $flagged[] = "{$path}:1: not valid PHP " . ($is === null ? 'after' : 'before')
                        . ' the codemods, so every line they change is flagged';
                }
            }
            // A file that can declare a global constant, which an attribute's
            // argument can name, by means no line shows -- a callable, a
            // variable function, a value made elsewhere -- is flagged whole.
            $defines = !$whole && (($was->definesConstants ?? false) || ($is->definesConstants ?? false));
            if ($defines) {
                $flagged[] = "{$path}:1: names define() or call_user_func(), which can declare a constant where nothing "
                    . 'shows it, so every line the codemods change is flagged';
            }
            // PHP counts a lone "\r" as a line break; GNU diff splits lines
            // on "\n" alone. A carriage return that ends no line numbers the
            // file's lines two ways, and AuthorizationMap's ranges would be
            // asked about the wrong ones: such a file is flagged whole.
            $breaks = !$whole && !$defines && (self::isPhp($path, $before) || self::isPhp($path, $now))
                && preg_match('/\r(?!\n)/', ($before ?? '') . "\n" . ($now ?? '')) === 1;
            if ($breaks) {
                $flagged[] = "{$path}:1: holds a carriage return that ends no line, which PHP counts as a line break and diff "
                    . 'may not, so every line the codemods change is flagged';
            }

            // A removed line is numbered as it stood, an added one as it
            // will stand. The `---`/`+++` header comes before the first hunk
            // and only there: inside one, a removed "-- x" reads "--- x". A
            // file added or deleted whole is flagged whole, above.
            $oldLine = $newLine = 0;
            $inHunk = false;
            foreach ($whole ? [] : explode("\n", $hunk) as $text) {
                if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)/', $text, $match) === 1) {
                    $oldLine = (int) $match[1] - 1;
                    $newLine = (int) $match[2] - 1;
                    $inHunk = true;

                    continue;
                }
                // "\ No newline at end of file" is about the line before it.
                if (!$inHunk || str_starts_with($text, '\\')) {
                    continue;
                }
                $removed = str_starts_with($text, '-');
                $added = str_starts_with($text, '+');
                $oldLine += $added ? 0 : 1;
                $newLine += $removed ? 0 : 1;
                if (!$removed && !$added) {
                    continue;
                }
                $flag = $was === null || $is === null || $defines || $breaks
                    || ($removed ? $was->covers($oldLine) : $is->covers($newLine))
                    || preg_match(self::AUTHORIZATION, $text) === 1;
                if ($flag) {
                    $flagged[] = "{$path}:" . max($removed ? $oldLine : $newLine, 1) . ': ' . trim($text);
                }
            }

            // What each class and method declares, before against after:
            // what no single changed line shows, such as an attribute
            // commented out by lines that name nothing.
            if ($was !== null && $is !== null) {
                foreach (array_unique([...array_keys($is->entries), ...array_keys($was->entries)]) as $key) {
                    if ($was->signature($key) !== $is->signature($key)) {
                        $entry = $is->entries[$key] ?? $was->entries[$key];
                        $flagged[] = "{$path}:{$entry['line']}: authorization of {$entry['label']} was {$was->describe($key)}, now {$is->describe($key)}";
                    }
                }
            }
        }

        // Any change at all can change a global constant, whatever file it
        // is in: authorization that rests on one is flagged with it.
        if ($patch !== '' || $flagged !== []) {
            $flagged = [...$flagged, ...self::unverifiable($result)];
        }

        return [$patch, $flagged];
    }

    /**
     * Every authorization attribute in the app, as the codemods left it,
     * whose arguments rest on what no reading of the code can pin down: a
     * global constant, which any code can declare -- with define() under a
     * name no string spells, and before the file that seems to declare it
     * -- or something only running the code says (a `new`, a call,
     * `parent::` or `static::`). Class constants and enum cases are
     * followed to what they rest on, through the app's own declarations; a
     * class the app does not declare is not the patch's to change.
     *
     * @return list<string>
     */
    private static function unverifiable(string $root): array
    {
        $maps = [];
        foreach (self::filesUnder($root) as $path) {
            $code = (string) file_get_contents($root . '/' . $path);
            if (self::patchable($path) && self::isPhp($path, $code) && ($map = self::read($code)) !== null) {
                $maps[$path] = $map;
            }
        }

        $constants = [];
        $classes = [];
        /** @var array<string, list<string>> $declaredAt where each class is declared, as `path:line: Class` */
        $declaredAt = [];
        foreach ($maps as $path => $map) {
            foreach ($map->constants as $key => $refs) {
                // Declared twice, which declaration loads first decides.
                $constants[$key] = isset($constants[$key]) ? ["unverifiable:{$key}, declared twice"] : $refs;
            }
            foreach ($map->classes as $class) {
                $classes[$class] = true;
                $entry = $map->entries[$class] ?? ['line' => 1, 'label' => $class];
                $declaredAt[$class][] = "{$path}:{$entry['line']}: {$entry['label']}";
            }
        }

        // A class declared a second time stands in for the first wherever
        // its file loads first, with whatever authorization it declares --
        // and neither file's own attributes need change.
        $flagged = [];
        foreach ($declaredAt as $places) {
            if (\count($places) > 1) {
                foreach ($places as $place) {
                    $flagged[] = "{$place} is declared in more than one file, and whichever loads first decides its authorization";
                }
            }
        }

        foreach ($maps as $path => $map) {
            foreach ($map->references as $reference) {
                $why = self::restsOn($reference['refs'], $constants, $classes, []);
                if ($why !== null) {
                    $flagged[] = "{$path}:{$reference['line']}: authorization of {$reference['label']} rests on {$why}, "
                        . 'which a codemod can change where nothing shows it';
                }
            }
        }

        return $flagged;
    }

    /**
     * What, among $refs and what they lead to, cannot be pinned down; null
     * when everything can.
     *
     * @param list<string> $refs
     * @param array<string, list<string>> $constants
     * @param array<string, true> $classes
     * @param array<string, true> $seen
     */
    private static function restsOn(array $refs, array $constants, array $classes, array $seen): ?string
    {
        foreach ($refs as $ref) {
            [$kind, $name] = explode(':', $ref, 2);
            if ($kind === 'unverifiable') {
                return $name;
            }
            if ($kind === 'global') {
                return "the constant {$name}";
            }

            $at = (int) strrpos($name, '::');
            $class = strtolower(substr($name, 0, $at));
            $key = $class . substr($name, $at);
            if (isset($seen[$key])) {
                continue;
            }
            if (isset($constants[$key])) {
                $why = self::restsOn($constants[$key], $constants, $classes, [...$seen, $key => true]);
                if ($why !== null) {
                    return $why;
                }

                continue;
            }
            // The app's own class, without the constant: inherited, or
            // nowhere, and either way not followed.
            if (isset($classes[$class])) {
                return $name;
            }
        }

        return null;
    }

    /**
     * $text with each control character written out as an escape, `\x1b`
     * for ESC. The flags and failures are printed to the terminal of whoever
     * runs the codemods, and a codemod chooses part of them -- a line it
     * changed, an attribute's argument, what it printed -- where an escape
     * sequence moves the cursor and erases the lines above, the REVIEW block
     * among them. UTF-8's C1 controls too, which some terminals read as ESC
     * sequences. A tab stays, and so does a line break where $lines says the
     * text is several lines.
     */
    private static function printable(string $text, bool $lines = false): string
    {
        return (string) preg_replace_callback(
            $lines ? '/[\x00-\x08\x0b-\x1f\x7f]|\xc2[\x80-\x9f]/' : '/[\x00-\x08\x0a-\x1f\x7f]|\xc2[\x80-\x9f]/',
            static fn (array $match): string => \strlen($match[0]) === 1
                ? \sprintf('\x%02x', \ord($match[0]))
                : \sprintf('\u{%x}', \ord($match[0][1])),
            $text,
        );
    }

    /**
     * Whether a patch can name $path as it is: letters, digits, `.`, `_`, `-`
     * and `/`, in relative segments that are neither `.` nor `..`, and not
     * starting with `-`. Any other character is one a patch tool may read
     * otherwise than the diff that wrote it -- a line break starts a line
     * of the patch's own, whitespace ends a name for `patch`, a quote or a
     * backslash starts an escaped one for `git apply` -- and then the flags
     * would be about one file while the patch changed another.
     */
    public static function patchable(string $path): bool
    {
        if (preg_match('#^(?!-)[A-Za-z0-9._/-]+\z#', $path) !== 1) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether PHP could run $path: by its name, or by any `<?` in it. With
     * short_open_tag on -- PHP's default when no php.ini sets it, as in the
     * official images and FrankenPHP's static build -- `<?` followed by
     * anything opens PHP, so `<?namespace App;` in a .tpl file declares a
     * class. An XML declaration is read as PHP too: a false alarm, where
     * missing one let a second declaration of a class go unread.
     */
    private static function isPhp(string $path, ?string $contents): bool
    {
        return preg_match('/\.(php\d?|phtml|phar|inc)$/i', $path) === 1
            || ($contents !== null && str_contains($contents, '<?'));
    }

    /**
     * $code read as a runtime with short_open_tag on would run it, whatever
     * this process's php.ini says: the tokenizer follows the setting, and a
     * php.ini-development turns it off, which would read the class after a
     * `<?` as text. Each short tag is written long first; only letters and
     * a space are added, so every line keeps its number.
     *
     * @return AuthorizationMap|null null when $code is not valid PHP
     */
    private static function read(?string $code): ?AuthorizationMap
    {
        try {
            return $code === null
                ? AuthorizationMap::empty()
                : AuthorizationMap::of((string) preg_replace('/<\?(?!=|php(?:\s|\z))/i', '<?php ', $code));
        } catch (\CompileError) {
            return null;
        }
    }

    private static function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . '/tinvar-codemods-' . bin2hex(random_bytes(8));
        if (!mkdir($path, 0o700)) {
            throw new \RuntimeException("Cannot create {$path}.");
        }

        return realpath($path) ?: $path;
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
