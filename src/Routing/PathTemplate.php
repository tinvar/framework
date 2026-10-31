<?php

declare(strict_types=1);

namespace Tinvar\Routing;

/**
 * A path template's one compiler, for a page's or layout's (RouteRegistry)
 * and an endpoint's (EndpointRegistry) alike.
 *
 * The two registries checked templates each their own way, and had drifted:
 * the endpoints' refused a `.` or `..` segment and anchored its patterns
 * with /D, and the pages' did neither -- so `/racks/..` registered as a
 * page, and a literal with a newline after it passed `$`. One compiler, so
 * the two cannot come apart again; each registry words its own refusal.
 */
final class PathTemplate
{
    /** A literal segment: what a path segment holds unescaped, and never `.` or `..`. */
    private const string LITERAL = '/^[A-Za-z0-9._~\-]+$/D';

    /** A parameter's name: an identifier. */
    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /** One spelling of a template: a leading slash, no doubled or trailing one. */
    public static function normalize(string $template): string
    {
        $template = '/' . trim($template, '/');
        $template = (string) preg_replace('#/+#', '/', $template);

        return $template === '/' ? '/' : rtrim($template, '/');
    }

    /**
     * The template's segments and its parameters' names, in order.
     *
     * @return array{list<string>, list<string>}
     * @throws PathTemplateRefused naming what is wrong and the part it is wrong in
     */
    public static function compile(string $template): array
    {
        $segments = CompiledRoute::split(self::normalize($template));
        $names = [];
        foreach ($segments as $segment) {
            if (!CompiledRoute::isParameter($segment)) {
                if (preg_match(self::LITERAL, $segment) !== 1 || $segment === '.' || $segment === '..') {
                    throw new PathTemplateRefused(PathTemplateRefused::LITERAL, $segment);
                }

                continue;
            }

            $name = substr($segment, 1, -1);
            if (preg_match(self::NAME, $name) !== 1) {
                throw new PathTemplateRefused(PathTemplateRefused::NAME, $name);
            }
            if (\in_array($name, $names, true)) {
                throw new PathTemplateRefused(PathTemplateRefused::TWICE, $name);
            }
            $names[] = $name;
        }

        return [$segments, $names];
    }
}
