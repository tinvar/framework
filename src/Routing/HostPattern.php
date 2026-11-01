<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Http\HostName;

/**
 * A host a route is held to, as #[Domain] writes it (ADR 0149).
 *
 * Labels, dot-separated, read right to left as DNS reads them:
 *
 *   admin.example.com      an exact host
 *   {account}.example.com  any one label in front of example.com, bound
 *                          to the route's `account` parameter
 *   admin.{root}           one label in front of the domain the
 *                          application's Domains configures as `root`
 *   {root}                 that configured domain itself
 *
 * A `{name}` in the last place names a configured domain -- one or more
 * labels, from the environment, so `localhost` in dev and
 * `shop.example.com` in production need no change to the code. Anywhere
 * else it is a parameter, and takes exactly one label: a wildcard
 * certificate covers one level, and a parameter that took any depth would
 * hand a route to a name nobody chose. A parameter can never be last --
 * nobody's routes live on any top-level domain -- and a pattern with no
 * configured domain at its end ends in two literal labels or more, or in
 * `localhost`, after its last parameter, so `{tenant}.com` is refused
 * rather than serving every name under .com.
 *
 * Literals are written as a host is compared (HostName): lowercase, no
 * port, no trailing dot, no address. One spelling per pattern, so two that
 * mean the same host are seen to by their shape().
 */
final readonly class HostPattern
{
    /** A label as a host name holds it, lowercase. */
    private const string LABEL = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

    /** A parameter's or a configured domain's name: an identifier. */
    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]*\z/';

    /**
     * @param string $pattern as written
     * @param list<string> $labels left to right, each a literal or `{name}`
     * @param list<string> $parameters the parameters' names, left to right
     * @param string|null $root the configured domain it ends in, or null
     */
    private function __construct(
        public string $pattern,
        public array $labels,
        public array $parameters,
        public ?string $root,
    ) {}

    /** @throws HostPatternRefused naming what is wrong */
    public static function compile(string $pattern): self
    {
        if ($pattern === '' || $pattern !== trim($pattern)) {
            throw new HostPatternRefused($pattern, 'is empty or has spaces around it');
        }
        if (str_contains($pattern, ':') || str_contains($pattern, '/')) {
            throw new HostPatternRefused($pattern, 'names a port, a scheme or a path; a host pattern is a host alone');
        }
        if (str_ends_with($pattern, '.')) {
            throw new HostPatternRefused($pattern, 'ends in a dot; write the host without it');
        }

        $labels = explode('.', $pattern);
        $last = \count($labels) - 1;
        $parameters = [];
        $root = null;

        foreach ($labels as $index => $label) {
            if (str_starts_with($label, '{') && str_ends_with($label, '}')) {
                $name = substr($label, 1, -1);
                if (preg_match(self::NAME, $name) !== 1) {
                    throw new HostPatternRefused($pattern, "has '{$label}', and '{$name}' is not a name");
                }
                if ($index === $last) {
                    $root = $name;
                    continue;
                }
                if (\in_array($name, $parameters, true)) {
                    throw new HostPatternRefused($pattern, "names the parameter '{$name}' twice");
                }
                $parameters[] = $name;
                continue;
            }

            if (preg_match(self::LABEL, $label) !== 1) {
                throw new HostPatternRefused(
                    $pattern,
                    "has the label '{$label}', which is not a host name's: lowercase letters, digits and inner hyphens, "
                    . 'or a whole {name}',
                );
            }
        }

        if ($root === null) {
            // The literal tail after the last parameter: what the pattern is
            // held under, which must be a domain someone owns.
            $tail = [];
            for ($i = $last; $i >= 0 && !str_starts_with($labels[$i], '{'); $i--) {
                array_unshift($tail, $labels[$i]);
            }
            if ($parameters !== [] && \count($tail) < 2 && $tail !== ['localhost']) {
                throw new HostPatternRefused(
                    $pattern,
                    'puts a parameter in front of ' . ($tail === [] ? 'nothing' : "'{$tail[0]}'") . ', which would serve '
                    . 'every name under it; end it in a domain of two labels or more, or in a configured {root}',
                );
            }
            if ($parameters === [] && HostName::normalize($pattern) !== $pattern) {
                throw new HostPatternRefused($pattern, 'is not a host name');
            }
        }

        return new self($pattern, $labels, $parameters, $root);
    }

    /**
     * Its spelling with every parameter's name dropped: two patterns of one
     * shape match exactly the same hosts.
     */
    public function shape(): string
    {
        $out = [];
        foreach ($this->labels as $index => $label) {
            $isParameter = str_starts_with($label, '{') && $index < \count($this->labels) - 1;
            $out[] = $isParameter ? '{}' : $label;
        }

        return implode('.', $out);
    }

    /**
     * Its labels with the configured domain written out, right to left;
     * `{}` for each parameter.
     *
     * @param array<string, string> $roots configured name => host
     * @return list<string>
     * @throws \LogicException when it names a domain $roots does not configure
     */
    public function resolved(array $roots): array
    {
        $labels = [];
        foreach ($this->labels as $index => $label) {
            if ($this->root !== null && $index === \count($this->labels) - 1) {
                $host = $roots[$this->root] ?? throw new \LogicException(
                    "The host pattern '{$this->pattern}' ends in {{$this->root}}, and the application's Domains configures "
                    . "no domain called '{$this->root}'.",
                );
                array_push($labels, ...explode('.', $host));
                continue;
            }
            $labels[] = str_starts_with($label, '{') ? '{}' : $label;
        }

        return array_reverse($labels);
    }

    /**
     * The parameters a normalized host gives this pattern, or null when it
     * is not one of its hosts.
     *
     * @param array<string, string> $roots configured name => host
     * @return array<string, string>|null
     */
    public function match(string $host, array $roots): ?array
    {
        $want = $this->resolved($roots);
        $have = array_reverse(explode('.', $host));
        if (\count($want) !== \count($have)) {
            return null;
        }

        $values = [];
        foreach ($want as $index => $label) {
            if ($label === '{}') {
                $values[] = $have[$index];
            } elseif ($label !== $have[$index]) {
                return null;
            }
        }

        // Collected right to left; the parameters are named left to right.
        return $this->parameters === [] ? [] : array_combine($this->parameters, array_reverse($values));
    }

    /**
     * The host for these parameters.
     *
     * @param array<string, string|int> $parameters
     * @param array<string, string> $roots configured name => host
     * @throws HostPatternRefused for a missing parameter, or one that is not one label
     */
    public function host(array $parameters, array $roots): string
    {
        $out = [];
        foreach ($this->labels as $index => $label) {
            if ($this->root !== null && $index === \count($this->labels) - 1) {
                $out[] = $roots[$this->root] ?? throw new \LogicException(
                    "The host pattern '{$this->pattern}' names the domain '{$this->root}', which is not configured.",
                );
                continue;
            }
            if (!str_starts_with($label, '{')) {
                $out[] = $label;
                continue;
            }

            $name = substr($label, 1, -1);
            if (!\array_key_exists($name, $parameters)) {
                throw new HostPatternRefused($this->pattern, "needs a value for its parameter '{$name}'");
            }
            $value = (string) $parameters[$name];
            if (preg_match(self::LABEL, $value) !== 1) {
                throw new HostPatternRefused($this->pattern, "cannot take '{$value}' as its parameter '{$name}': a parameter is one label");
            }
            $out[] = $value;
        }

        return implode('.', $out);
    }

    /**
     * Ranks this pattern against another for the same host, as paths are
     * ranked: compared from the right, the first literal wins over a
     * parameter -- `admin.{root}` is asked before `{account}.{root}`.
     *
     * @param array<string, string> $roots
     */
    public function beats(self $other, array $roots): int
    {
        $mine = $this->resolved($roots);
        $theirs = $other->resolved($roots);
        $shared = min(\count($mine), \count($theirs));
        for ($i = 0; $i < $shared; $i++) {
            $a = $mine[$i] === '{}';
            $b = $theirs[$i] === '{}';
            if ($a !== $b) {
                return $a ? 1 : -1;
            }
        }

        return \count($theirs) <=> \count($mine);
    }
}
