<?php

declare(strict_types=1);

/**
 * #[Config] classes (ADR 0085): typed and immutable, read from the
 * environment or a secret's file, refused at boot when a key is missing or
 * malformed -- naming the variable, never the value -- and never anything
 * the encoder, the mapper, a dump or a log shows a secret through.
 */

namespace Tinvar\Tests\Config\Configs;

use Tinvar\Attributes\Config;
use Tinvar\Attributes\Env;
use Tinvar\Attributes\ListOf;
use Tinvar\Attributes\MapOf;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Config\Secret;
use Tinvar\Data\Data;
use Tinvar\Data\DataBoundaryException;
use Tinvar\Data\DataMappingException;
use Tinvar\Data\Encoder;
use Tinvar\Observability\Redactor;
use Tinvar\Server\ConfigCommand;

enum Level: string
{
    case Info = 'info';
    case Debug = 'debug';
}

enum Tier: int
{
    case One = 1;
    case Two = 2;
}

#[Config(prefix: 'APPTEST_')]
final readonly class AppConfig
{
    public function __construct(
        public string $name,
        public int $workers = 4,
        public float $ratio = 0.5,
        public bool $verbose = false,
        public Level $level = Level::Info,
        public ?Tier $tier = null,
        public ?string $mailLog = null,
        #[\SensitiveParameter, Env(file: true)]
        public ?Secret $apiToken = null,
        #[Env('OTHER_TOKEN'), \SensitiveParameter]
        public ?Secret $otherToken = null,
    ) {
        if ($this->apiToken !== null && $this->apiToken->length() < 8) {
            throw ConfigException::invalid('APPTEST_API_TOKEN', 'must be 8 characters or more');
        }
    }
}

#[Config]
final readonly class RequiredSecret
{
    public function __construct(
        #[\SensitiveParameter]
        public Secret $key,
    ) {}
}

#[Config]
final readonly class LeakyConstructor
{
    public function __construct(public string $url)
    {
        throw new \InvalidArgumentException("bad url {$url}");
    }
}

#[Config]
final readonly class UnmarkedSecret
{
    public function __construct(public ?Secret $key = null) {}
}

#[Config]
final readonly class SensitiveString
{
    public function __construct(
        #[\SensitiveParameter]
        public ?string $key = null,
    ) {}
}

#[Config]
final readonly class SecretWithDefault
{
    public function __construct(
        #[\SensitiveParameter]
        public Secret $key = new Secret('in-the-source'),
    ) {}
}

#[Config]
final readonly class ArrayKey
{
    /** @param list<string> $hosts */
    public function __construct(public array $hosts = []) {}
}

enum Shade: string
{
    case Dark = 'dark';
    case Light = 'light';
}

#[Config(prefix: 'LISTTEST_')]
final readonly class Lists
{
    /**
     * @param list<string> $hosts
     * @param list<int> $ports
     * @param array<string, int> $limits
     * @param list<Shade> $shades
     * @param array<string, bool> $flags
     */
    public function __construct(
        #[ListOf('string')]
        public array $hosts = [],
        #[ListOf('int')]
        public array $ports = [80, 443],
        #[MapOf('int')]
        public array $limits = [],
        #[ListOf(Shade::class)]
        public array $shades = [],
        #[MapOf('bool')]
        public ?array $flags = null,
    ) {}
}

#[Config]
final readonly class SecretList
{
    /** @param list<Secret> $keys */
    public function __construct(#[ListOf(Secret::class)] public array $keys = []) {}
}

#[Config]
final readonly class StringList
{
    public function __construct(#[ListOf('string')] public string $hosts = '') {}
}

#[Config]
final readonly class ListAndMap
{
    /** @param array<mixed> $both */
    public function __construct(#[ListOf('string'), MapOf('string')] public array $both = []) {}
}

#[Config]
final readonly class FileOnString
{
    public function __construct(#[Env(file: true)] public ?string $path = null) {}
}

#[Config]
final class Mutable
{
    public function __construct(public ?string $x = null) {}
}

#[Config]
final readonly class AlsoReadsName
{
    public function __construct(#[Env('APPTEST_NAME')] public ?string $other = null) {}
}

final readonly class NotConfig
{
    public function __construct(public ?string $x = null) {}
}

final readonly class NameData extends Data
{
    public function __construct(public string $name) {}
}

const SECRET = 'sk_live_0123456789abcdef';

beforeEach(function (): void {
    Configs::forget();
});

/**
 * The message of what $load throws, which must be a ConfigException.
 *
 * @param \Closure(): mixed $load
 */
function refusal(\Closure $load): string
{
    try {
        $load();
    } catch (ConfigException $e) {
        return $e->getMessage();
    }

    throw new \RuntimeException('Nothing was refused.');
}

it('reads each key by its prefix and name, typed, with defaults where unset', function (): void {
    $config = Configs::load(AppConfig::class, [
        'APPTEST_NAME' => 'pellwick',
        'APPTEST_WORKERS' => '12',
        'APPTEST_RATIO' => '0.25',
        'APPTEST_VERBOSE' => '1',
        'APPTEST_LEVEL' => 'debug',
        'APPTEST_TIER' => '2',
        'APPTEST_MAIL_LOG' => '/var/log/mail.log',
        'APPTEST_API_TOKEN' => SECRET,
        'OTHER_TOKEN' => 'another-secret',
    ]);

    expect($config->name)->toBe('pellwick')
        ->and($config->workers)->toBe(12)
        ->and($config->ratio)->toBe(0.25)
        ->and($config->verbose)->toBeTrue()
        ->and($config->level)->toBe(Level::Debug)
        ->and($config->tier)->toBe(Tier::Two)
        ->and($config->mailLog)->toBe('/var/log/mail.log')
        ->and($config->apiToken?->reveal())->toBe(SECRET)
        ->and($config->otherToken?->reveal())->toBe('another-secret');

    $defaults = Configs::load(AppConfig::class, ['APPTEST_NAME' => 'x', 'APPTEST_WORKERS' => '']);
    expect($defaults->workers)->toBe(4)
        ->and($defaults->level)->toBe(Level::Info)
        ->and($defaults->tier)->toBeNull()
        ->and($defaults->apiToken)->toBeNull();
});

it('refuses every missing and malformed key at once, naming each variable and never its value', function (): void {
    $message = refusal(static fn (): object => Configs::load(AppConfig::class, [
        'APPTEST_WORKERS' => '12abc' . SECRET,
        'APPTEST_RATIO' => 'NaN',
        'APPTEST_VERBOSE' => 'yes',
        'APPTEST_LEVEL' => 'loud',
        'APPTEST_TIER' => '3',
    ]));

    expect($message)
        ->toContain('APPTEST_NAME is required')
        ->toContain('APPTEST_WORKERS must be a whole number')
        ->toContain('APPTEST_RATIO must be a number')
        ->toContain('APPTEST_VERBOSE must be 1 or 0')
        ->toContain('APPTEST_LEVEL must be one of info, debug')
        ->toContain('APPTEST_TIER must be one of 1, 2')
        ->not->toContain(SECRET)
        ->not->toContain('12abc')
        ->not->toContain('loud')
        ->not->toContain('yes');
});

it('takes a whole number and nothing near one', function (string $given): void {
    expect(refusal(static fn (): object => Configs::load(AppConfig::class, ['APPTEST_NAME' => 'x', 'APPTEST_WORKERS' => $given])))
        ->toContain('APPTEST_WORKERS must be a whole number');
})->with([' 4', '4 ', '+4', '04', '4.0', '1e3', '99999999999999999999']);

it('lets the class refuse a value, by variable, and hides what anything else it throws says', function (): void {
    expect(refusal(static fn (): object => Configs::load(AppConfig::class, ['APPTEST_NAME' => 'x', 'APPTEST_API_TOKEN' => 'short'])))
        ->toBe('Configuration refused: APPTEST_API_TOKEN must be 8 characters or more.');

    $message = refusal(static fn (): object => Configs::load(LeakyConstructor::class, ['URL' => 'https://user:' . SECRET . '@example.test']));
    expect($message)->toContain(LeakyConstructor::class)
        ->toContain('InvalidArgumentException')
        ->not->toContain(SECRET)
        ->not->toContain('example.test');
});

it('reads a secret from the file its _FILE variable names, and refuses both, a relative path and an unreadable file', function (): void {
    $dir = sys_get_temp_dir() . '/tinvar-config-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o700);
    file_put_contents("{$dir}/token", SECRET . "\n");
    file_put_contents("{$dir}/empty", "\n");

    try {
        $config = Configs::load(AppConfig::class, ['APPTEST_NAME' => 'x', 'APPTEST_API_TOKEN_FILE' => "{$dir}/token"]);
        expect($config->apiToken?->reveal())->toBe(SECRET);

        expect(refusal(static fn (): object => Configs::load(AppConfig::class, ['APPTEST_NAME' => 'x', 'APPTEST_API_TOKEN' => SECRET, 'APPTEST_API_TOKEN_FILE' => "{$dir}/token"])))
            ->toContain('APPTEST_API_TOKEN and APPTEST_API_TOKEN_FILE are both set')
            ->not->toContain(SECRET);
        expect(refusal(static fn (): object => Configs::load(AppConfig::class, ['APPTEST_NAME' => 'x', 'APPTEST_API_TOKEN_FILE' => 'token'])))
            ->toContain('APPTEST_API_TOKEN_FILE must be an absolute path');
        expect(refusal(static fn (): object => Configs::load(AppConfig::class, ['APPTEST_NAME' => 'x', 'APPTEST_API_TOKEN_FILE' => "{$dir}/missing"])))
            ->toContain('APPTEST_API_TOKEN_FILE names a file this process cannot read')
            ->not->toContain($dir);
        expect(refusal(static fn (): object => Configs::load(AppConfig::class, ['APPTEST_NAME' => 'x', 'APPTEST_API_TOKEN_FILE' => "{$dir}/empty"])))
            ->toContain('APPTEST_API_TOKEN_FILE names an empty file');
        // Only where the key asks for a file: OTHER_TOKEN_FILE means nothing.
        expect(Configs::load(AppConfig::class, ['APPTEST_NAME' => 'x', 'OTHER_TOKEN_FILE' => "{$dir}/token"])->otherToken)->toBeNull();

        expect(refusal(static fn (): object => Configs::load(RequiredSecret::class, [])))->toContain('KEY is required');
    } finally {
        array_map(unlink(...), glob("{$dir}/*") ?: []);
        rmdir($dir);
    }
});

it('keeps one object per worker while the environment stays, and reads again when it changes', function (): void {
    putenv('APPTEST_NAME=first');
    try {
        $first = Configs::load(AppConfig::class);
        expect(Configs::load(AppConfig::class))->toBe($first);

        putenv('APPTEST_NAME=second');
        $second = Configs::load(AppConfig::class);
        expect($second)->not->toBe($first)
            ->and($second->name)->toBe('second');

        putenv('APPTEST_NAME');
        expect(refusal(static fn (): object => Configs::load(AppConfig::class)))->toContain('APPTEST_NAME is required');
    } finally {
        putenv('APPTEST_NAME');
    }
});

it('loads every class at boot and says everything wrong in one exception', function (): void {
    $message = refusal(static function (): void {
        Configs::loadAll([AppConfig::class, RequiredSecret::class], ['APPTEST_VERBOSE' => 'on']);
    });

    expect($message)->toContain('APPTEST_NAME is required')
        ->toContain('APPTEST_VERBOSE must be 1 or 0')
        ->toContain('KEY is required');
});

it('refuses a class that is not config, or would let a secret be printed', function (string $class, string $says): void {
    expect(static fn (): array => Configs::keys($class))->toThrow(\LogicException::class, $says);
})->with([
    'no attribute' => [NotConfig::class, 'is not declared #[Config]'],
    'not readonly' => [Mutable::class, 'must be a final readonly class'],
    'a Secret unmarked' => [UnmarkedSecret::class, 'mark it #[\SensitiveParameter]'],
    'a sensitive string' => [SensitiveString::class, 'type it Secret'],
    'a secret in the source' => [SecretWithDefault::class, 'a secret in the source is no secret'],
    'an array unmarked' => [ArrayKey::class, 'mark it #[ListOf] or #[MapOf]'],
    'a list of secrets' => [SecretList::class, 'never Secret'],
    'a list that is a string' => [StringList::class, 'a list or a map is an array'],
    'both a list and a map' => [ListAndMap::class, 'mark it #[ListOf] or #[MapOf], once'],
    'a file for a string' => [FileOnString::class, 'only a Secret does'],
]);

it('holds the declarations, and one key per variable, in tinvar check', function (): void {
    $violations = array_map(strval(...), Configs::check([AppConfig::class, AlsoReadsName::class, UnmarkedSecret::class]));

    expect($violations)->toHaveCount(2)
        ->and($violations[0])->toContain('[config-variable-once]')->toContain('APPTEST_NAME is read by ' . AppConfig::class . '::$name')
        ->and($violations[1])->toContain('[config-must-compile]')->toContain(UnmarkedSecret::class);
    expect(Configs::check([AppConfig::class, RequiredSecret::class]))->toBe([]);
});

it('never shows a secret through a dump, an export, JSON, a cast, a log or a trace', function (): void {
    $config = Configs::load(AppConfig::class, ['APPTEST_NAME' => 'x', 'APPTEST_API_TOKEN' => SECRET]);

    ob_start();
    var_dump($config);
    $dumped = (string) ob_get_clean();

    expect($dumped)->toContain(Secret::REDACTED)->not->toContain(SECRET)
        ->and(print_r($config, true))->not->toContain(SECRET)
        ->and(var_export($config, true))->not->toContain(SECRET)
        ->and(json_encode($config, JSON_THROW_ON_ERROR))->toContain('"apiToken":"[redacted]"')->not->toContain(SECRET)
        ->and(json_encode((array) $config->apiToken, JSON_THROW_ON_ERROR))->toBe('[]')
        ->and(get_object_vars($config->apiToken ?? throw new \LogicException()))->toBe([])
        ->and(json_encode(new Redactor()->context(['config' => $config, 'token' => $config->apiToken]), JSON_THROW_ON_ERROR))->not->toContain(SECRET);

    expect(static fn (): string => serialize($config))->toThrow(\LogicException::class, 'never serialized');
    expect(static fn (): object => clone $config->apiToken)->toThrow(\Error::class);

    try {
        (static function (Secret $token): never {
            throw new \RuntimeException('failed');
        })($config->apiToken);
    } catch (\RuntimeException $e) {
        expect($e->getTraceAsString())->not->toContain(SECRET);
    }

    expect($config->apiToken->equals(SECRET))->toBeTrue()
        ->and($config->apiToken->equals(SECRET . 'x'))->toBeFalse();
});

it('is never encoded to the renderer, nor mapped into Data', function (): void {
    $config = Configs::load(AppConfig::class, ['APPTEST_NAME' => 'x', 'APPTEST_API_TOKEN' => SECRET]);
    $encoder = new Encoder();

    expect(static fn (): mixed => $encoder->encode($config))->toThrow(DataBoundaryException::class)
        ->and(static fn (): mixed => $encoder->encode(['token' => $config->apiToken]))->toThrow(DataBoundaryException::class)
        ->and(static fn (): Data => NameData::from($config))->toThrow(DataMappingException::class, 'config never crosses to the renderer');
});

it('lists every key, where it comes from, and no secret, and fails where the boot would', function (): void {
    $lines = [];
    $errors = [];
    $command = new ConfigCommand(
        [AppConfig::class],
        ['APPTEST_NAME' => 'pellwick', 'APPTEST_API_TOKEN' => SECRET, 'APPTEST_MAIL_LOG' => 'smtp://me:' . SECRET . '@mail.test'],
        static function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
        static function (string $line) use (&$errors): void {
            $errors[] = $line;
        },
    );

    expect($command->run())->toBe(0);
    $out = implode("\n", $lines);
    expect($out)->toContain('APPTEST_NAME *')
        ->toContain('pellwick')
        ->toMatch('/APPTEST_API_TOKEN\s+\?Secret\s+env\s+\[secret\]/')
        ->toMatch('/APPTEST_WORKERS\s+int\s+default\s+4/')
        ->toMatch('/APPTEST_LEVEL\s+' . preg_quote(Level::class, '/') . '\s+default\s+info/')
        ->toMatch('/OTHER_TOKEN\s+\?Secret\s+unset/')
        ->not->toContain(SECRET)
        ->and($errors)->toBe([]);

    $lines = [];
    $failing = new ConfigCommand([AppConfig::class], ['APPTEST_WORKERS' => 'twelve'], static function (string $line) use (&$lines): void {
        $lines[] = $line;
    }, static function (string $line) use (&$errors): void {
        $errors[] = $line;
    });
    expect($failing->run())->toBe(1)
        ->and(implode("\n", $errors))->toContain('APPTEST_NAME is required')->toContain('APPTEST_WORKERS must be a whole number')
        ->and(implode("\n", $errors))->not->toContain('twelve');
});

// ADR 0111: a list or a map, each item read as strictly as a key of its
// type alone, and every malformed one named by its place, never its text.
it('reads a list and a map, item by item, as strictly as one key', function (): void {
    $config = Configs::load(Lists::class, [
        'LISTTEST_HOSTS' => 'a.test, b.test',
        'LISTTEST_PORTS' => '8080',
        'LISTTEST_LIMITS' => 'actions=600, sign-in.address=0',
        'LISTTEST_SHADES' => 'dark,light',
        'LISTTEST_FLAGS' => 'beta=1,legacy=0',
    ]);

    expect($config->hosts)->toBe(['a.test', 'b.test'])
        ->and($config->ports)->toBe([8080])
        ->and($config->limits)->toBe(['actions' => 600, 'sign-in.address' => 0])
        ->and($config->shades)->toBe([Shade::Dark, Shade::Light])
        ->and($config->flags)->toBe(['beta' => true, 'legacy' => false])
        ->and(Configs::load(Lists::class, [])->ports)->toBe([80, 443])
        ->and(Configs::load(Lists::class, [])->flags)->toBeNull();
});

it('refuses a list or map with anything malformed, naming each by its place and none by its value', function (): void {
    $message = refusal(static function (): void {
        Configs::load(Lists::class, [
            'LISTTEST_HOSTS' => 'a.test,,b.test,',
            'LISTTEST_PORTS' => '80,04,sekret-port',
            'LISTTEST_LIMITS' => 'actions=600,actions=5,no-equals,bad key=1',
            'LISTTEST_SHADES' => 'dark,purple',
        ]);
    });

    expect($message)->toContain('LISTTEST_HOSTS has an empty item, the 2nd')
        ->toContain('LISTTEST_HOSTS has an empty item, the 4th')
        ->toContain("LISTTEST_PORTS's 2nd item must be a whole number")
        ->toContain("LISTTEST_PORTS's 3rd item must be a whole number")
        ->toContain('LISTTEST_LIMITS gives the key actions twice')
        ->toContain("LISTTEST_LIMITS's 3rd entry is not key=value")
        ->toContain("LISTTEST_LIMITS's 4th entry is not key=value")
        ->toContain("LISTTEST_SHADES's 2nd item must be one of dark, light")
        ->not->toContain('sekret-port')
        ->not->toContain('purple');
});

it('lists a list or map with its type and default', function (): void {
    $rows = Configs::inspect(Lists::class, [])['keys'];
    $described = array_map(static fn (array $row): array => [$row['key']->describe(), $row['value']], $rows);

    expect($described)->toBe([
        ['list<string>', null],
        ['list<int>', '80,443'],
        ['map<int>', null],
        ['list<' . Shade::class . '>', null],
        ['?map<bool>', null],
    ]);
});
