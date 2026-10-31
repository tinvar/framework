<?php

declare(strict_types=1);

/**
 * The one edit a generator makes to a Kernel's or a console's list: a
 * class added at its end, once, in the file's own style -- and none at all
 * where the file does not read exactly as such a list does.
 */

namespace Tinvar\Tests\Generators\ClassList;

use Tinvar\Generators\ClassList;
use Tinvar\Generators\RegistrationRefused;
use Tinvar\Support\PrivateDirectory;

/** Writes $source to a file of its own, runs $body with its path, and hands back what is in it after. */
function edited(string $source, \Closure $body): string
{
    $directory = PrivateDirectory::create('class-list');
    $file = "{$directory}/Kernel.php";
    file_put_contents($file, $source);
    try {
        $body($file);

        return (string) file_get_contents($file);
    } finally {
        @unlink($file);
        PrivateDirectory::remove($directory);
    }
}

const KERNEL = <<<'PHP'
<?php

namespace App;

use App\Pages\Home;
use App\Pages\Layout;
use Tinvar\Http\Kernel as HttpKernel;

final class Kernel
{
    /** The pages. */
    public const array ROUTES = [
        Layout::class,
        Home::class, // the home page
    ];

    public const array QUERIES = [Queries\RecentNotes::class];

    public const array ACTIONS = [];
}

PHP;

it('adds a class on a line of its own, imported in order among the file\'s imports', function () {
    $after = edited(KERNEL, function (string $file): void {
        expect(ClassList::add($file, 'ROUTES', 'App\Pages\Notes'))->toBeTrue();
    });

    expect($after)->toContain("use App\\Pages\\Layout;\nuse App\\Pages\\Notes;\nuse Tinvar\\Http\\Kernel as HttpKernel;")
        ->and($after)->toContain("        Home::class, // the home page\n        Notes::class,\n    ];");
});

it('adds to a list on one line, and to an empty one', function () {
    $after = edited(KERNEL, function (string $file): void {
        ClassList::add($file, 'QUERIES', 'App\Queries\Stats');
        ClassList::add($file, 'ACTIONS', 'App\Actions\NoteActions');
    });

    expect($after)->toContain('public const array QUERIES = [Queries\RecentNotes::class, Stats::class];')
        ->and($after)->toContain('public const array ACTIONS = [NoteActions::class];')
        ->and($after)->toContain("use App\\Actions\\NoteActions;\nuse App\\Pages\\Home;")
        ->and($after)->toContain("use App\\Queries\\Stats;\nuse Tinvar\\Http\\Kernel as HttpKernel;");
});

it('adds nothing twice, under whatever name the file already gives the class', function (string $class) {
    $after = edited(KERNEL, function (string $file) use ($class): void {
        expect(ClassList::add($file, 'ROUTES', $class))->toBeFalse()
            ->and(ClassList::add($file, 'QUERIES', 'App\Queries\RecentNotes'))->toBeFalse();
    });

    expect($after)->toBe(KERNEL);
})->with(['App\Pages\Home', '\App\Pages\Layout', 'app\pages\home']);

it('names a class in full where its short name is taken', function () {
    $after = edited(KERNEL, function (string $file): void {
        ClassList::add($file, 'ROUTES', 'Other\Home');
    });

    expect($after)->toContain("        \\Other\\Home::class,\n    ];")
        ->and($after)->not->toContain('use Other\Home;');
});

// A bare name the file already uses means a class of its namespace: an
// import of that short name would rebind it -- an entry of the list, or a
// type anywhere else in the file -- to the class being added.
it('names a class in full where its short name is used bare anywhere in the file', function (string $class, string $short) {
    $source = <<<'PHP'
        <?php

        namespace Shop;

        use Shop\Pages\Catalog;

        final class Kernel
        {
            public const array ROUTES = [
                Catalog::class,
                Search::class,
                Admin\Panel::class,
            ];

            public function mailer(): Mailer
            {
                return new Mailer();
            }
        }

        PHP;
    $after = edited($source, function (string $file) use ($class): void {
        expect(ClassList::add($file, 'ROUTES', $class))->toBeTrue();
    });

    expect($after)->not->toContain("use {$class};")
        ->and($after)->toContain("        \\{$class}::class,\n    ];")
        ->and($after)->toContain("        Search::class,\n");
})->with([
    'an entry of the list' => ['Shop\Pages\Search', 'Search'],
    'a type elsewhere in the file' => ['Shop\Mail\Mailer', 'Mailer'],
    'the first segment of a qualified entry' => ['Shop\Other\Admin', 'Admin'],
]);

it('adds to a console\'s list outside any namespace, in full', function () {
    $console = "<?php\n\nuse Tinvar\\Routing\\RouteRegistry;\n\nconst APP_MODELS = [\n    Shop\\Models\\Product::class,\n    Shop\\Models\\Order::class,\n];\n\n\$x = array_map(static function () use (\$y) { return 1; }, []);\n";
    $after = edited($console, function (string $file): void {
        expect(ClassList::add($file, 'APP_MODELS', 'Shop\Models\Shelf'))->toBeTrue()
            ->and(ClassList::add($file, 'APP_MODELS', 'Shop\Models\Order'))->toBeFalse();
    });

    expect($after)->toContain("    Shop\\Models\\Order::class,\n    Shop\\Models\\Shelf::class,\n];");
});

it('refuses a list it cannot read, and leaves the file as it was', function (string $source, string $why) {
    $after = edited($source, function (string $file) use ($why): void {
        expect(fn () => ClassList::add($file, 'ROUTES', 'App\Pages\Notes'))->toThrow(RegistrationRefused::class, $why);
    });

    expect($after)->toBe($source);
})->with([
    'a spread' => ["<?php\nnamespace App;\nuse A\\B;\nfinal class K { const array ROUTES = [...self::BASE, B::class]; }\n", 'something other than Name::class'],
    'a key' => ["<?php\nnamespace App;\nuse A\\B;\nfinal class K { const ROUTES = ['b' => B::class]; }\n", 'something other than Name::class'],
    'a string' => ["<?php\nnamespace App;\nuse A\\B;\nfinal class K { const ROUTES = ['App\\\\B']; }\n", 'something other than Name::class'],
    'no list' => ["<?php\nnamespace App;\nuse A\\B;\nfinal class K { const QUERIES = []; }\n", 'declares no ROUTES'],
    'two lists' => ["<?php\nnamespace App;\nuse A\\B;\nfinal class K { const ROUTES = []; }\nfinal class L { const ROUTES = []; }\n", 'more than once'],
    'not an array' => ["<?php\nnamespace App;\nuse A\\B;\nfinal class K { const ROUTES = self::OTHER; }\n", 'not a list'],
    'a grouped import' => ["<?php\nnamespace App;\nuse A\\{B, C};\nfinal class K { const ROUTES = [B::class]; }\n", 'grouped use'],
]);

it('never writes through a symbolic link', function () {
    $directory = PrivateDirectory::create('class-list');
    file_put_contents("{$directory}/real.php", KERNEL);
    symlink("{$directory}/real.php", "{$directory}/Kernel.php");
    try {
        expect(fn () => ClassList::add("{$directory}/Kernel.php", 'ROUTES', 'App\Pages\Notes'))->toThrow(RegistrationRefused::class, 'symbolic link')
            ->and(file_get_contents("{$directory}/real.php"))->toBe(KERNEL);
    } finally {
        @unlink("{$directory}/Kernel.php");
        @unlink("{$directory}/real.php");
        PrivateDirectory::remove($directory);
    }
});
