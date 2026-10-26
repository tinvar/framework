<?php

declare(strict_types=1);

/**
 * The layer rule: support uses nothing of Tinvar's, the database uses only
 * support, the framework uses either, and the testing layer and codegen all
 * three, but not each other (ADR 0054).
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
        'an attribute the ORM reads' => ['Tinvar\Attributes\Table', 'database'],
        'an attribute it does not' => ['Tinvar\Attributes\Panel', 'framework'],
        'the container' => ['Tinvar\Container\Container', 'support'],
        'its attribute' => ['Tinvar\Attributes\Dependency', 'support'],
        'a lazy value' => ['Tinvar\Data\Lazy', 'support'],
        'the rest of Data' => ['Tinvar\Data\Mapper', 'framework'],
        'tenancy' => ['Tinvar\Tenancy\TenantHierarchy', 'framework'],
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

        expect($layers->names())->toBe(['support', 'database', 'framework', 'testing', 'codegen'])
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
            "use Tinvar\\Support\\Str;\nuse Tinvar\\Attributes\\Table;\nuse Tinvar\\Data\\Lazy;",
            'public function a(): string { return Schema\Table::class . \Tinvar\Orm\Model::class . namespace\Model::class; }',
        );

        expect(crossed($source))->toBe([]);
    });

    it('fails importing a framework class', function () {
        expect(crossed(orm('use Tinvar\Tenancy\TenantHierarchy;')))
            ->toBe(['Example.php:7: database uses framework, Tinvar\Tenancy\TenantHierarchy (use)']);
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
        expect(crossed(orm('use Tinvar\Attributes\{Table, Column as Field, Panel};')))
            ->toBe(['Example.php:7: database uses framework, Tinvar\Attributes\Panel (use)']);
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
    it('fails support using the database', function () {
        $source = "<?php\nnamespace Tinvar\\Support;\n\nuse Tinvar\\Orm\\Model;\n\nfinal class Example {}\n";

        expect(crossed($source))->toBe(['Example.php:4: support uses database, Tinvar\Orm\Model (use)']);
    });

    it('takes a shared namespace class by class', function () {
        $table = "<?php\nnamespace Tinvar\\Attributes;\n\nuse Tinvar\\Http\\Request;\n\nfinal class Table {}\n";
        $panel = "<?php\nnamespace Tinvar\\Attributes;\n\nuse Tinvar\\Http\\Request;\n\nfinal class Panel {}\n";

        expect(crossed($table))->toBe(['Example.php:4: database uses framework, Tinvar\Http\Request (use)'])
            ->and(crossed($panel))->toBe([]);
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
            }
            PHP;
        $classes = ['Tinvar\Attributes\Panel', 'Tinvar\Attributes\ClassAttributes', 'Tinvar\Attributes\Concerns', 'Tinvar\Attributes\Column'];

        expect(Layers::framework()->checkSource($source, 'Column.php', NAMESPACES, $classes))->toBe([
            'Column.php:4: database uses framework, Tinvar\Attributes\Panel (code)',
            'Column.php:7: database uses framework, Tinvar\Attributes\Concerns (code)',
            'Column.php:9: database uses framework, Tinvar\Attributes\Panel (code)',
            'Column.php:14: database uses framework, Tinvar\Attributes\ClassAttributes (code)',
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

            expect($layers->check($root))->toBe(['src/Orm/Example.php:4: database uses framework, Tinvar\Live\Invalidator (comment)']);
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
