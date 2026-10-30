<?php

declare(strict_types=1);

/**
 * Each module of the framework is held at runtime as well as in its names
 * (ADR 0114): its tests run in a process of their own, with every class of
 * a module beside it, of the kernel and of the packages above refused.
 */

namespace Tinvar\Tests\Packages\ModuleAlone;

use Tinvar\Packages\Layers;
use Tinvar\Tests\Support\Fixtures\ModuleAlone;

require_once __DIR__ . '/../Support/Fixtures/module-alone.php';

const ROOT = __DIR__ . '/../../../..';

/** The core's layers (ADR 0114), which are no module. */
const CORE = ['http', 'auth', 'data', 'reads', 'render', 'identity', 'entry', 'requests', 'operations'];

/**
 * Runs $script in a PHP process of its own, the autoloader and the guard
 * loaded first.
 *
 * @return array{int, list<string>}
 */
function run(string $script): array
{
    $prelude = 'require ' . var_export(ROOT . '/vendor/autoload.php', true) . '; require ' . var_export(__DIR__ . '/../Support/Fixtures/module-alone.php', true) . '; ';
    exec(escapeshellarg(\PHP_BINARY) . ' -r ' . escapeshellarg($prelude . $script) . ' 2>&1', $output, $status);

    return [$status, $output];
}

it('runs every module of the map alone, from a directory of its own', function () {
    $modules = Layers::modules()->names();
    $around = [...Layers::framework()->names(), ...CORE, 'kernel'];

    expect(array_values(array_diff($modules, $around)))->toBe(ModuleAlone::MODULES);
    foreach (ModuleAlone::MODULES as $module) {
        expect(is_dir(ROOT . '/packages/framework/tests/' . ucfirst($module)))->toBeTrue("{$module} has no tests");
    }
});

it('lets a module load itself, what it stands on, the core and any attribute, and refuses the rest', function () {
    [$status, $output] = run(<<<'PHP'
        use Tinvar\Tests\Support\Fixtures\ModuleAlone;
        ModuleAlone::hold('mail');
        foreach (['Tinvar\Mail\Mailer', 'Tinvar\Jobs\Dispatcher', 'Tinvar\Queue\ArrayQueue', 'Tinvar\Http\Request', 'Tinvar\Http\Endpoints\FragmentEndpoint', 'Tinvar\Attributes\Webhook', 'Tinvar\Orm\Orm'] as $class) {
            echo (class_exists($class) || interface_exists($class) ? 'loaded' : 'missing') . " {$class}\n";
        }
        foreach (['Tinvar\Webhooks\OutboundWebhooks', 'Tinvar\Events\Events', 'Tinvar\Http\Kernel', 'Tinvar\Testing\Testing', 'Tinvar\Tenancy\TenantHosts'] as $class) {
            try {
                class_exists($class);
                echo "loaded {$class}\n";
            } catch (\LogicException $e) {
                echo "refused {$class}\n";
            }
        }
        PHP);

    expect($status)->toBe(0, implode("\n", $output))
        ->and(array_values(array_filter($output, static fn (string $line): bool => !str_starts_with($line, 'mail alone: '))))->toBe([
            'loaded Tinvar\Mail\Mailer',
            'loaded Tinvar\Jobs\Dispatcher',
            'loaded Tinvar\Queue\ArrayQueue',
            'loaded Tinvar\Http\Request',
            'loaded Tinvar\Http\Endpoints\FragmentEndpoint',
            'loaded Tinvar\Attributes\Webhook',
            'loaded Tinvar\Orm\Orm',
            'refused Tinvar\Webhooks\OutboundWebhooks',
            'refused Tinvar\Events\Events',
            'refused Tinvar\Http\Kernel',
            'refused Tinvar\Testing\Testing',
            'refused Tinvar\Tenancy\TenantHosts',
        ]);
});

it('fails a run that a class it may not load was declared in before it began, or after by hand', function () {
    [$before, $beforeOutput] = run(<<<'PHP'
        class_exists('Tinvar\Webhooks\OutboundWebhooks');
        Tinvar\Tests\Support\Fixtures\ModuleAlone::hold('mail');
        PHP);
    [$after, $afterOutput] = run(<<<'PHP'
        Tinvar\Tests\Support\Fixtures\ModuleAlone::hold('queue');
        // A class declared without the autoloader, as a file required by
        // hand declares one: the test's own literal, nothing it was given.
        eval('namespace Tinvar\Mail; final class Smuggled {}');
        echo "ran\n";
        PHP);

    expect($before)->not->toBe(0)
        ->and(implode("\n", $beforeOutput))->toContain('mail alone: loaded before the run began: Tinvar\Webhooks\OutboundWebhooks')
        ->and($after)->toBe(1)
        ->and($afterOutput)->toContain('ran')
        ->and(implode("\n", $afterOutput))->toContain('queue alone: declared though refused: Tinvar\Mail\Smuggled');
});

it('refuses a module there is none of, and holding a process twice', function () {
    [$unknown, $unknownOutput] = run("Tinvar\\Tests\\Support\\Fixtures\\ModuleAlone::hold('kernel');");
    [$twice, $twiceOutput] = run("Tinvar\\Tests\\Support\\Fixtures\\ModuleAlone::hold('mail'); Tinvar\\Tests\\Support\\Fixtures\\ModuleAlone::hold('jobs');");

    expect($unknown)->not->toBe(0)
        ->and(implode("\n", $unknownOutput))->toContain("No module 'kernel' runs alone")
        ->and($twice)->not->toBe(0)
        ->and(implode("\n", $twiceOutput))->toContain('This process already holds mail.');
});

it('passes every module\'s own tests with nothing beside it loadable', function () {
    $environment = array_diff_key(getenv(), array_flip(['PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN', 'TINVAR_MODULE_ALONE']));
    $process = proc_open([\PHP_BINARY, ROOT . '/bin/module-alone', '--', '--colors=never'], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, ROOT, $environment);
    expect($process)->toBeResource();
    \assert(\is_resource($process));
    $output = (string) stream_get_contents($pipes[1]);
    $status = proc_close($process);

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('ok: ' . implode(', ', ModuleAlone::MODULES) . ' each alone')
        ->and($output)->not->toContain('alone: refused');
});
