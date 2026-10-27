<?php

declare(strict_types=1);

/**
 * The layer rule: support uses nothing of Tinvar's, the database uses only
 * support, the framework uses either, and the testing layer and codegen all
 * three, but not each other (ADR 0054). Each layer is a package, and a file
 * is in the layer of the package whose directory holds it (ADR 0055).
 *
 * Every way PHP lets a file name a class counts -- an import, a fully
 * qualified name, a name through an alias or below the namespace, a
 * `::class`, a class name in a string -- and so does a qualified name in a
 * comment or docblock.
 */

namespace Tinvar\Tests\Packages\Layers;

use Tinvar\Packages\Layers;
use Tinvar\Packages\SourceReferences;

/** The framework's namespaces, as check() finds them, for comments' shortened names. */
const NAMESPACES = ['Tinvar', 'Tinvar\Orm', 'Tinvar\Orm\Schema', 'Tinvar\Http', 'Tinvar\Live', 'Tinvar\Testing', 'Tinvar\Tenancy', 'Tinvar\Support'];

/** @return list<string> */
function crossed(string $source): array
{
    return Layers::framework()->checkSource($source, 'Example.php', NAMESPACES);
}

/**
 * A map with a namespace shared class by class, as the framework's was
 * before its packages were split: the map can still say so.
 */
function shared(): Layers
{
    return new Layers(
        ['database' => ['Tinvar\\Orm\\', 'Tinvar\\Attributes\\Table', 'Tinvar\\Attributes\\Column'], 'framework' => ['Tinvar\\']],
        ['framework' => ['database']],
    );
}

/**
 * A workspace of files under a temporary root, read by $layers' check().
 *
 * @param array<string, string> $files path => contents
 * @return list<string>
 */
function checked(Layers $layers, array $files): array
{
    $root = sys_get_temp_dir() . '/tinvar-layers-' . bin2hex(random_bytes(4));
    foreach ($files as $path => $contents) {
        if (!is_dir(\dirname("{$root}/{$path}"))) {
            mkdir(\dirname("{$root}/{$path}"), 0o777, true);
        }
        file_put_contents("{$root}/{$path}", $contents);
    }

    try {
        return $layers->check($root);
    } finally {
        exec('rm -rf ' . escapeshellarg($root));
    }
}

/** A file in the database layer, with $body in its class. */
function orm(string $imports = '', string $body = '', string $docblock = ''): string
{
    return <<<PHP
        <?php

        declare(strict_types=1);

        namespace Tinvar\\Orm;

        {$imports}

        {$docblock}
        final class Example
        {
            {$body}
        }
        PHP;
}

describe('the layers', function () {
    it('passes the repository as it is', function () {
        expect(Layers::framework()->check(\dirname(__DIR__, 4)))->toBe([]);
    });

    it('puts a class in the layer of its most specific member', function (string $name, ?string $layer) {
        expect(Layers::framework()->layerOf($name))->toBe($layer);
    })->with([
        'the ORM' => ['Tinvar\Orm\Orm', 'database'],
        'below the ORM' => ['Tinvar\Orm\Schema\Table', 'database'],
        'an attribute the ORM reads' => ['Tinvar\Orm\Attributes\Table', 'database'],
        'its name before the split' => ['Tinvar\Attributes\Table', 'framework'],
        'an attribute it does not read' => ['Tinvar\Attributes\Panel', 'framework'],
        'the container' => ['Tinvar\Container\Container', 'support'],
        'its attribute' => ['Tinvar\Container\Dependency', 'support'],
        'a lazy value' => ['Tinvar\Support\Lazy', 'support'],
        'Data' => ['Tinvar\Data\Mapper', 'framework'],
        'the tenant walk' => ['Tinvar\Tenancy\TenantHierarchy', 'tenancy'],
        'the tree port the ORM bounds' => ['Tinvar\Orm\TenantBounds', 'database'],
        'tenancy' => ['Tinvar\Tenancy\TenantHosts', 'tenancy'],
        'the port tenancy implements' => ['Tinvar\Http\TenantResolver', 'framework'],
        'codegen' => ['Tinvar\Codegen\TypeScriptGenerator', 'codegen'],
        'the testing layer' => ['Tinvar\Testing\ModelLeaks', 'testing'],
        'a namespace by its name' => ['Tinvar\Orm', 'database'],
        'a leading backslash' => ['\Tinvar\Orm\Model', 'database'],
        'in any case' => ['tinvar\orm\model', 'database'],
        'a name that only starts like a member' => ['Tinvar\Ormish\Thing', 'framework'],
        'a class that only starts like a member' => ['Tinvar\Data\LazyList', 'framework'],
        'not Tinvar' => ['Shop\Models\Product', null],
        'nothing' => ['PDO', null],
    ]);

    it('lets a layer use itself and the layers below it it names, never one above or beside it', function () {
        $layers = Layers::framework();

        expect($layers->names())->toBe(['support', 'database', 'framework', 'tenancy', 'testing', 'codegen'])
            ->and($layers->mayUse('database', 'support'))->toBeTrue()
            ->and($layers->mayUse('database', 'database'))->toBeTrue()
            ->and($layers->mayUse('framework', 'database'))->toBeTrue()
            ->and($layers->mayUse('testing', 'framework'))->toBeTrue()
            ->and($layers->mayUse('codegen', 'support'))->toBeTrue()
            ->and($layers->mayUse('database', 'framework'))->toBeFalse()
            ->and($layers->mayUse('support', 'database'))->toBeFalse()
            ->and($layers->mayUse('framework', 'testing'))->toBeFalse()
            ->and($layers->mayUse('framework', 'codegen'))->toBeFalse()
            ->and($layers->mayUse('codegen', 'testing'))->toBeFalse()
            ->and($layers->mayUse('testing', 'codegen'))->toBeFalse()
            ->and($layers->mayUse('tenancy', 'framework'))->toBeTrue()
            ->and($layers->mayUse('tenancy', 'database'))->toBeTrue()
            ->and($layers->mayUse('framework', 'tenancy'))->toBeFalse()
            ->and($layers->mayUse('database', 'tenancy'))->toBeFalse()
            ->and($layers->mayUse('testing', 'tenancy'))->toBeFalse()
            ->and($layers->mayUse('codegen', 'tenancy'))->toBeFalse()
            ->and($layers->mayUse('tenancy', 'testing'))->toBeFalse()
            ->and($layers->mayUse('database', 'nowhere'))->toBeFalse()
            ->and($layers->mayUse('nowhere', 'nowhere'))->toBeFalse();
    });

    it('refuses a map in which a layer would use one not below it', function (array $uses, string $message) {
        expect(fn () => new Layers(['low' => ['A\\'], 'high' => ['B\\']], $uses))
            ->toThrow(\InvalidArgumentException::class, $message);
    })->with([
        'one above' => [['low' => ['high']], "Layer 'low' may use only layers listed before it, and 'high' is not one."],
        'itself' => [['low' => ['low']], "Layer 'low' may use only layers listed before it, and 'low' is not one."],
        'none there is' => [['high' => ['middle']], "Layer 'high' may use only layers listed before it, and 'middle' is not one."],
        'for none there is' => [['middle' => ['low']], 'No such layer: middle.'],
    ]);
});

describe('a database class', function () {
    it('passes using only support and itself', function () {
        $source = orm(
            "use Tinvar\\Support\\Str;\nuse Tinvar\\Orm\\Attributes\\Table;\nuse Tinvar\\Support\\Lazy;",
            'public function a(): string { return Schema\Table::class . \Tinvar\Orm\Model::class . namespace\Model::class; }',
        );

        expect(crossed($source))->toBe([]);
    });

    it('fails importing a framework class', function () {
        expect(crossed(orm('use Tinvar\Http\Request;')))
            ->toBe(['Example.php:7: database uses framework, Tinvar\Http\Request (use)']);
    });

    it('fails importing a tenancy class, the tenant walk among them', function () {
        expect(crossed(orm('use Tinvar\Tenancy\TenantHierarchy;')))
            ->toBe(['Example.php:7: database uses tenancy, Tinvar\Tenancy\TenantHierarchy (use)']);
    });

    it('fails naming one fully qualified, in a type, a new or a ::class', function (string $body) {
        expect(crossed(orm(body: $body)))->toHaveCount(1)
            ->and(crossed(orm(body: $body))[0])->toContain('database uses framework, Tinvar\Http\Request (code)');
    })->with([
        'a type' => ['public function a(\Tinvar\Http\Request $request): void {}'],
        'a new' => ['public function a(): object { return new \Tinvar\Http\Request(); }'],
        '::class' => ['public function a(): string { return \Tinvar\Http\Request::class; }'],
        'an instanceof' => ['public function a(object $o): bool { return $o instanceof \Tinvar\Http\Request; }'],
        'a static call' => ['public function a(): mixed { return \Tinvar\Http\Request::capture(); }'],
        'an attribute' => ['#[\Tinvar\Http\Request] public function a(): void {}'],
        'a trait' => ['use \Tinvar\Http\Request;'],
    ]);

    it('fails naming one through an imported namespace', function () {
        $violations = crossed(orm('use Tinvar\Http;', 'public function a(): string { return Http\Request::class; }'));

        expect($violations)->toBe([
            'Example.php:7: database uses framework, Tinvar\Http (use)',
            'Example.php:12: database uses framework, Tinvar\Http\Request (code)',
        ]);
    });

    it('fails on an alias at the import, wherever the alias is used', function () {
        expect(crossed(orm('use Tinvar\Http\Request as Incoming;', 'public function a(): string { return Incoming::class; }')))
            ->toBe(['Example.php:7: database uses framework, Tinvar\Http\Request (use)']);
    });

    it('fails on the one name of a group that crosses', function () {
        expect(crossed(orm('use Tinvar\Orm\Attributes\{Table, Column as Field};')))->toBe([])
            ->and(crossed(orm('use Tinvar\{Orm\Model, Http\Request as Incoming, Support\Str};')))
            ->toBe(['Example.php:7: database uses framework, Tinvar\Http\Request (use)']);
    });

    it('fails importing a framework function or constant', function () {
        expect(crossed(orm("use function Tinvar\\Http\\respond;\nuse const Tinvar\\Http\\LIMIT;")))->toBe([
            'Example.php:7: database uses framework, Tinvar\Http\respond (use)',
            'Example.php:8: database uses framework, Tinvar\Http\LIMIT (use)',
        ]);
    });

    it('fails naming one in a string, but not a namespace prefix', function () {
        $body = <<<'PHP'
            public function a(): array
            {
                return ['Tinvar\Http\Request', "\\Tinvar\\Live\\Invalidator", str_starts_with('x', 'Tinvar\Http\\')];
            }
            PHP;

        expect(crossed(orm(body: $body)))->toBe([
            'Example.php:14: database uses framework, Tinvar\Http\Request (string)',
            'Example.php:14: database uses framework, Tinvar\Live\Invalidator (string)',
        ]);
    });

    it('fails naming one in a docblock or a comment, shortened as these comments write them', function () {
        $docblock = <<<'PHP'
            /**
             * Told after the commit (see Live\Invalidator), checked by the
             * testing layer's \Tinvar\Testing\ModelLeaks.
             *
             * @param Tinvar\Http\Request $request
             */
            PHP;

        expect(crossed(orm(docblock: $docblock, body: '// Reset with Http\RequestScope.')))->toBe([
            'Example.php:10: database uses framework, Tinvar\Live\Invalidator (comment)',
            'Example.php:11: database uses testing, Tinvar\Testing\ModelLeaks (comment)',
            'Example.php:13: database uses framework, Tinvar\Http\Request (comment)',
            'Example.php:17: database uses framework, Tinvar\Http\RequestScope (comment)',
        ]);
    });

    it('passes a comment naming its own layer, or what is not Tinvar', function () {
        $docblock = "/**\n * Built from Schema\\Table, as App\\Models\\User is in an application, and see Orm\\Model.\n */";

        expect(crossed(orm(docblock: $docblock)))->toBe([]);
    });

    it('fails an import in a braced namespace as at the top of a file', function () {
        $source = "<?php\nnamespace Tinvar\\Orm {\n    use Tinvar\\Http\\Request;\n\n    final class Example {}\n}\n";

        expect(crossed($source))->toBe(['Example.php:3: database uses framework, Tinvar\Http\Request (use)']);
    });

    it('is read whole past a closure and a class body', function () {
        $body = <<<'PHP'
            use Concerns;

            public function a(int $x): \Closure
            {
                return function () use ($x): object { return new \Tinvar\Http\Request($x); };
            }
            PHP;

        expect(crossed(orm(body: $body)))
            ->toBe(['Example.php:16: database uses framework, Tinvar\Http\Request (code)']);
    });
});

describe('the other layers', function () {
    // ADR 0056: tenancy is a package an application opts into, so nothing
    // below it -- nor testing or codegen beside it -- may name it, in code,
    // a string or a comment.
    it('fails the framework, testing or codegen naming tenancy, and passes tenancy using the framework', function () {
        $file = static fn (string $namespace, string $use): string => "<?php\nnamespace {$namespace};\n\nuse {$use};\n\nfinal class Example {}\n";

        expect(crossed($file('Tinvar\\Http', 'Tinvar\\Tenancy\\Host')))->toBe(['Example.php:4: framework uses tenancy, Tinvar\Tenancy\Host (use)'])
            ->and(crossed($file('Tinvar\\Testing', 'Tinvar\\Tenancy\\TenantHierarchy')))->toBe(['Example.php:4: testing uses tenancy, Tinvar\Tenancy\TenantHierarchy (use)'])
            ->and(crossed($file('Tinvar\\Codegen', 'Tinvar\\Tenancy\\TenantSource')))->toBe(['Example.php:4: codegen uses tenancy, Tinvar\Tenancy\TenantSource (use)'])
            ->and(crossed("<?php\nnamespace Tinvar\\Http;\n\n/** Read through Tinvar\\Tenancy\\ResolveTenant. */\nfinal class Example {}\n"))->toHaveCount(1)
            ->and(crossed($file('Tinvar\\Tenancy', 'Tinvar\\Http\\TenantResolver')))->toBe([])
            ->and(crossed($file('Tinvar\\Tenancy', 'Tinvar\\Orm\\TenantSubtrees')))->toBe([])
            ->and(crossed($file('Tinvar\\Tenancy', 'Tinvar\\Testing\\Client')))->toBe(['Example.php:4: tenancy uses testing, Tinvar\Testing\Client (use)']);
    });

    it('fails support using the database', function () {
        $source = "<?php\nnamespace Tinvar\\Support;\n\nuse Tinvar\\Orm\\Model;\n\nfinal class Example {}\n";

        expect(crossed($source))->toBe(['Example.php:4: support uses database, Tinvar\Orm\Model (use)']);
    });

    it('takes a shared namespace class by class', function () {
        $table = "<?php\nnamespace Tinvar\\Attributes;\n\nuse Tinvar\\Http\\Request;\n\nfinal class Table {}\n";
        $panel = "<?php\nnamespace Tinvar\\Attributes;\n\nuse Tinvar\\Http\\Request;\n\nfinal class Panel {}\n";

        expect(shared()->checkSource($table, 'Example.php', NAMESPACES))->toBe(['Example.php:4: database uses framework, Tinvar\Http\Request (use)'])
            ->and(shared()->checkSource($panel, 'Example.php', NAMESPACES))->toBe([]);
    });

    it('fails a class of a shared namespace named bare, with no import to catch', function () {
        $source = <<<'PHP'
            <?php
            namespace Tinvar\Attributes;

            #[Panel]
            final class Column
            {
                use Concerns;

                public function a(\ReflectionClass $r, Panel $panel): array
                {
                    $this->Panel();
                    $x = self::Panel;
                    f(Panel: 1);
                    return ClassAttributes::of($r, self::class);
                }

                public function b(mixed $kind): int
                {
                    switch ($kind) {
                        case Kind::Admin:
                            return 1;
                    }

                    return 0;
                }
            }

            enum Local { case Panel; }
            PHP;
        $classes = ['Tinvar\Attributes\Panel', 'Tinvar\Attributes\ClassAttributes', 'Tinvar\Attributes\Concerns', 'Tinvar\Attributes\Kind', 'Tinvar\Attributes\Column'];

        expect(shared()->checkSource($source, 'Column.php', NAMESPACES, $classes))->toBe([
            'Column.php:4: database uses framework, Tinvar\Attributes\Panel (code)',
            'Column.php:7: database uses framework, Tinvar\Attributes\Concerns (code)',
            'Column.php:9: database uses framework, Tinvar\Attributes\Panel (code)',
            'Column.php:14: database uses framework, Tinvar\Attributes\ClassAttributes (code)',
            'Column.php:20: database uses framework, Tinvar\Attributes\Kind (code)',
        ]);
    });

    it('passes the framework using the database and support', function () {
        $source = "<?php\nnamespace Tinvar\\Http;\n\nuse Tinvar\\Orm\\Orm;\nuse Tinvar\\Support\\Str;\n\nfinal class Example {}\n";

        expect(crossed($source))->toBe([]);
    });

    it('fails the framework naming the testing layer, which is built on it', function () {
        $source = "<?php\nnamespace Tinvar\\Http;\n\nfinal class Example { public function a(): string { return \\Tinvar\\Testing\\Testing::class; } }\n";

        expect(crossed($source))->toBe(['Example.php:4: framework uses testing, Tinvar\Testing\Testing (code)']);
    });

    it('fails the framework naming codegen, which is built on it', function () {
        $source = "<?php\nnamespace Tinvar\\Generators;\n\n// As \\Tinvar\\Codegen\\TypeScriptGenerator does.\nfinal class Example {}\n";

        expect(crossed($source))->toBe(['Example.php:4: framework uses codegen, Tinvar\Codegen\TypeScriptGenerator (comment)']);
    });

    it('puts a file in the layer its path names, and fails one declaring another class', function () {
        $source = "<?php\nnamespace Tinvar\\Http;\n\nuse Tinvar\\Http\\Request;\n\nfinal class Escape {}\n";

        expect(Layers::framework()->checkSource($source, 'src/Orm/Escape.php', NAMESPACES, [], 'Tinvar\Orm\Escape'))->toBe([
            'src/Orm/Escape.php: declares Tinvar\Http\Escape, where its path names Tinvar\Orm\Escape',
            'src/Orm/Escape.php:4: database uses framework, Tinvar\Http\Request (use)',
        ]);
    });

    it('puts a file in its package\'s layer, and fails a class of another layer\'s namespace there', function () {
        $source = "<?php\nnamespace Tinvar\\Orm;\n\nuse Tinvar\\Http\\Request;\n\nfinal class Escape {}\n";

        expect(Layers::framework()->checkSource($source, 'packages/framework/src/Orm/Escape.php', NAMESPACES, [], 'Tinvar\Orm\Escape', 'framework'))->toBe([
            'packages/framework/src/Orm/Escape.php: Tinvar\Orm\Escape is in the database layer, and its file is in the framework package',
        ]);
    });

    it('reads each package whole, and fails a file in none of its PSR-4 directories', function () {
        $layers = new Layers(
            ['support' => ['Tinvar\\Support\\'], 'database' => ['Tinvar\\Orm\\'], 'framework' => ['Tinvar\\']],
            ['database' => ['support'], 'framework' => ['support', 'database']],
            ['packages/support/src/Support' => 'Tinvar\\Support\\', 'packages/database/src/Orm' => 'Tinvar\\Orm\\', 'packages/framework/src' => 'Tinvar\\'],
            ['packages/support/src' => 'support', 'packages/database/src' => 'database', 'packages/framework/src' => 'framework'],
        );

        expect(checked($layers, [
            'packages/support/src/Support/Str.php' => "<?php\nnamespace Tinvar\\Support;\n\nfinal class Str {}\n",
            'packages/support/src/Stray.php' => "<?php\nnamespace Tinvar\\Support;\n\nfinal class Stray {}\n",
            'packages/database/src/Orm/Model.php' => "<?php\nnamespace Tinvar\\Orm;\n\nuse Tinvar\\Support\\Str;\n\nfinal class Model {}\n",
            'packages/framework/src/Orm/Escape.php' => "<?php\nnamespace Tinvar\\Orm;\n\nfinal class Escape {}\n",
            'packages/framework/src/Http/Kernel.php' => "<?php\nnamespace Tinvar\\Http;\n\nuse Tinvar\\Orm\\Model;\n\nfinal class Kernel {}\n",
        ]))->toBe([
            'packages/support/src/Stray.php: in the support package, and in none of its PSR-4 directories, so its layer cannot be checked.',
            'packages/framework/src/Orm/Escape.php: Tinvar\Orm\Escape is in the database layer, and its file is in the framework package',
        ]);
    });

    // Composer autoloads through a link, and a directory iterator does not
    // walk into one: a database class behind a link to a directory would
    // load and never be read. A link is refused, wherever it points.
    it('fails a link in a package rather than skipping what is behind it', function () {
        $layers = new Layers(
            ['support' => ['Tinvar\\Support\\'], 'database' => ['Tinvar\\Orm\\'], 'framework' => ['Tinvar\\']],
            ['database' => ['support'], 'framework' => ['support', 'database']],
            ['packages/database/src/Orm' => 'Tinvar\\Orm\\', 'packages/framework/src' => 'Tinvar\\'],
            ['packages/database/src' => 'database', 'packages/framework/src' => 'framework'],
        );
        $root = sys_get_temp_dir() . '/tinvar-layers-' . bin2hex(random_bytes(4));
        mkdir("{$root}/packages/database/src/Orm", 0o777, true);
        mkdir("{$root}/packages/framework/src/Http", 0o777, true);
        mkdir("{$root}/outside", 0o777, true);
        file_put_contents("{$root}/packages/framework/src/Http/Request.php", "<?php\nnamespace Tinvar\\Http;\n\nfinal class Request {}\n");
        file_put_contents("{$root}/outside/Escape.php", "<?php\nnamespace Tinvar\\Orm\\Linked;\n\nuse Tinvar\\Http\\Request;\n\nfinal class Escape {}\n");
        symlink("{$root}/outside", "{$root}/packages/database/src/Orm/Linked");
        symlink("{$root}/outside/Escape.php", "{$root}/packages/database/src/Orm/Escape.php");

        try {
            $violations = $layers->check($root);
            sort($violations);

            expect($violations)->toBe([
                'packages/database/src/Orm/Escape.php: a link, so what it points at is not read as the package\'s own; its layer cannot be checked.',
                'packages/database/src/Orm/Linked: a link, so what it points at is not read as the package\'s own; its layer cannot be checked.',
            ]);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    });

    it('fails a source in none of the packages, and a package that is not there', function () {
        $layers = new Layers(
            ['framework' => ['Tinvar\\']],
            [],
            ['elsewhere/src' => 'Tinvar\\'],
            ['packages/framework/src' => 'framework'],
        );

        expect($layers->check(sys_get_temp_dir()))->toBe([
            'elsewhere/src: a source in none of the packages, so its layer cannot be checked.',
            'packages/framework/src: not a directory, so its layers cannot be checked.',
        ]);
    });

    it('fails a file in no layer rather than skipping it', function () {
        expect(crossed("<?php\nnamespace Elsewhere;\n\nfinal class Example {}\n"))
            ->toBe(['Example.php: Elsewhere\Example is in no layer; name it in Layers.']);
    });

    it('fails a source it cannot read rather than passing it', function () {
        $layers = new Layers(['only' => ['Tinvar\\']], sources: ['nowhere/src' => 'Tinvar\\']);

        expect($layers->check(sys_get_temp_dir()))->toBe(['nowhere/src: not a directory, so its layers cannot be checked.']);
    });

    it('reads a directory with every namespace it declares known to its comments', function () {
        $root = sys_get_temp_dir() . '/tinvar-layers-' . bin2hex(random_bytes(4));
        mkdir($root . '/src/Orm', 0o777, true);
        mkdir($root . '/src/Live', 0o777, true);
        file_put_contents($root . '/src/Orm/Example.php', "<?php\nnamespace Tinvar\\Orm;\n\n/** Told by Live\\Invalidator. */\nfinal class Example {}\n");
        file_put_contents($root . '/src/Live/Invalidator.php', "<?php\nnamespace Tinvar\\Live;\n\nfinal class Invalidator {}\n");

        try {
            $layers = new Layers(['database' => ['Tinvar\\Orm\\'], 'framework' => ['Tinvar\\']], ['framework' => ['database']], ['src' => 'Tinvar\\']);

            $violations = $layers->check($root);
            sort($violations);

            expect($violations)->toBe(['src/Orm/Example.php:4: database uses framework, Tinvar\Live\Invalidator (comment)']);
        } finally {
            unlink($root . '/src/Orm/Example.php');
            unlink($root . '/src/Live/Invalidator.php');
            rmdir($root . '/src/Orm');
            rmdir($root . '/src/Live');
            rmdir($root . '/src');
            rmdir($root);
        }
    });
});

describe('the references in a file', function () {
    it('knows what it declares, past ::class and an anonymous class', function () {
        $source = "<?php\nnamespace Tinvar\\Orm;\n\n\$a = Model::class;\n\$b = new class {};\nenum Kind {}\n";

        expect(SourceReferences::of($source)->declared)->toBe('Tinvar\Orm\Kind')
            ->and(SourceReferences::of($source)->namespace)->toBe('Tinvar\Orm');
    });
});
