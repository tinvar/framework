<?php

declare(strict_types=1);

/**
 * TINVAR_EDITOR (ADR 0132): a URL template that makes each `file:line` the
 * dev toolbar and the dev overlay show a link to the developer's editor,
 * held to the editors' own schemes so it can never be a script, filled
 * with a path that is encoded and never walks out of a mapped prefix.
 */

namespace Tinvar\Tests\Config\EditorTemplate;

use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Config\EditorTemplate;
use Tinvar\Config\FrameworkConfig;
use Tinvar\Dev\DevToolbar;
use Tinvar\Http\DevOverlay;
use Tinvar\Support\PrivateDirectory;

it('links an absolute file:line in the editor\'s own scheme, each segment encoded', function () {
    $vscode = EditorTemplate::of('vscode://file{file}:{line}');
    $phpstorm = EditorTemplate::of('phpstorm://open?file={file}&line={line}', ['/app=/Users/ada/my shop/']);
    \assert($vscode !== null && $phpstorm !== null);

    expect($vscode->link('/srv/app/Pages/Catalog.php:48'))->toBe('vscode://file/srv/app/Pages/Catalog.php:48')
        ->and($vscode->link('/srv/a b/"x"<y>&z.php:3'))->toBe('vscode://file/srv/a%20b/%22x%22%3Cy%3E%26z.php:3')
        ->and($vscode->link('C:\\work\\Shop.php:9'))->toBe('vscode://file/C:/work/Shop.php:9')
        ->and($phpstorm->link('/app/Pages/Catalog.php:48'))->toBe('phpstorm://open?file=/Users/ada/my%20shop/Pages/Catalog.php&line=48')
        // A prefix is a whole leading directory, not any run of characters.
        ->and($phpstorm->link('/application/X.php:1'))->toBe('phpstorm://open?file=/application/X.php&line=1')
        ->and($phpstorm->toArray())->toBe(['template' => 'phpstorm://open?file={file}&line={line}', 'paths' => [['/app', '/Users/ada/my shop']]]);
});

it('links no site that is not an absolute path and a line, or that walks with . or ..', function (string $site) {
    expect(EditorTemplate::of('vscode://file{file}:{line}')?->link($site))->toBeNull();
})->with([
    'relative' => ['app/Pages/Catalog.php:48'],
    'no line' => ['/app/Pages/Catalog.php'],
    'a line that is not digits' => ['/app/Pages/Catalog.php:4x'],
    'up a directory' => ['/app/../etc/passwd:1'],
    'this directory' => ['/app/./x.php:1'],
    'a control character' => ["/app/x\n.php:1"],
    'empty' => [''],
]);

it('refuses a template that is not an editor\'s, by variable and never by value', function (string $template) {
    try {
        Configs::load(FrameworkConfig::class, ['TINVAR_EDITOR' => $template]);
        $refused = null;
    } catch (ConfigException $e) {
        $refused = $e->getMessage();
    }

    expect($refused)->toContain('TINVAR_EDITOR')
        ->and($refused)->not->toContain($template);
})->with([
    'a script' => ['javascript:alert(document.cookie)//{file}{line}'],
    'a script in capitals' => ['JavaScript:alert(1)//{file}{line}'],
    'data' => ['data:text/html,<script>{file}{line}</script>'],
    'a web page' => ['https://example.com/open?f={file}&l={line}'],
    'a file URL' => ['file://{file}#{line}'],
    'no scheme' => ['//evil.example/{file}:{line}'],
    'a quote to end the attribute' => ['vscode://file{file}:{line}" onmouseover="alert(1)'],
    'an angle bracket' => ['vscode://file{file}:{line}<script>'],
    'a space' => ['vscode://file {file}:{line}'],
    'no line' => ['vscode://file{file}'],
    'no file' => ['vscode://file:{line}'],
    'another placeholder' => ['vscode://file{file}:{line}:{column}'],
    'too long' => ['vscode://file{file}:{line}?' . str_repeat('a', 600)],
]);

it('refuses a path map that is not two absolute paths, or that names no editor', function (string $paths, string $editor) {
    expect(fn () => Configs::load(FrameworkConfig::class, ['TINVAR_EDITOR' => $editor, 'TINVAR_EDITOR_PATHS' => $paths]))
        ->toThrow(ConfigException::class, 'TINVAR_EDITOR_PATHS');
})->with([
    'no =' => ['/app', 'vscode://file{file}:{line}'],
    'relative' => ['app=/Users/ada', 'vscode://file{file}:{line}'],
    'walking out' => ['/app=/Users/ada/../root', 'vscode://file{file}:{line}'],
    'no editor' => ['/app=/Users/ada', ''],
]);

it('reads nothing set as no editor, and a template from FrameworkConfig', function () {
    expect(Configs::load(FrameworkConfig::class, [])->editorTemplate())->toBeNull()
        ->and(Configs::load(FrameworkConfig::class, ['TINVAR_EDITOR' => 'cursor://file{file}:{line}', 'TINVAR_EDITOR_PATHS' => '/app=/Users/ada'])->editorTemplate()?->link('/app/X.php:2'))
        ->toBe('cursor://file/Users/ada/X.php:2');
    Configs::forget();
});

it('holds the toolbar\'s script to the same schemes', function () {
    $script = (string) file_get_contents(__DIR__ . '/../../resources/dev-toolbar/toolbar.js');
    preg_match('/const SCHEMES = \[(.*?)\];/s', $script, $m);
    preg_match_all("/'([a-z-]+)'/", $m[1] ?? '', $schemes);

    expect($schemes[1])->toBe(EditorTemplate::SCHEMES);
});

it('hands the toolbar its editor, and none where TINVAR_EDITOR names none', function () {
    $directory = PrivateDirectory::create('tinvar-dev-test');
    DevToolbar::prepare($directory);
    try {
        $config = static function (?DevToolbar $toolbar): array {
            \assert($toolbar !== null);
            $drawn = (new \ReflectionProperty(DevToolbar::class, 'editor'))->getValue($toolbar);

            return $drawn instanceof EditorTemplate ? $drawn->toArray() : [];
        };
        $env = [DevToolbar::ENV => $directory, 'TINVAR_DEV' => '1'];

        expect($config(DevToolbar::fromEnvironment(static fn (): string => '', env: $env)))->toBe([])
            ->and($config(DevToolbar::fromEnvironment(static fn (): string => '', env: [...$env, 'TINVAR_EDITOR' => 'zed://file{file}:{line}'])))
            ->toBe(['template' => 'zed://file{file}:{line}', 'paths' => []]);
    } finally {
        PrivateDirectory::remove($directory);
        Configs::forget();
    }
});

it('links the overlay\'s file:line to the editor, escaped, and leaves it text without one', function () {
    $e = new \LogicException('the price table has no row');
    $line = __LINE__ - 1;
    $linked = DevOverlay::render($e, 'd1', 'n0nce', EditorTemplate::of('vscode://file{file}:{line}'));
    $plain = DevOverlay::render($e, 'd1', 'n0nce');

    expect($linked)->toContain('<p class="where"><a href="vscode://file' . implode('/', array_map(rawurlencode(...), explode('/', __FILE__))) . ':' . $line . '">' . __FILE__ . ':' . $line . '</a></p>')
        ->and($plain)->toContain('<p class="where">' . __FILE__ . ':' . $line . '</p>')
        ->and($plain)->not->toContain('<a ');
});
