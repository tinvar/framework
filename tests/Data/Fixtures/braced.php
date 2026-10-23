<?php

declare(strict_types=1);

// Two braced namespace blocks, each with its own imports, and a closure's
// `use` at file scope between them -- every shape that confused the import
// reader.

namespace Tinvar\Tests\Data\Braced\Parts {
    final readonly class Part extends \Tinvar\Data\Data
    {
        public function __construct(public string $id) {}
    }

    final readonly class Other extends \Tinvar\Data\Data
    {
        public function __construct(public string $name) {}
    }
}

namespace Tinvar\Tests\Data\Braced\First {
    use Tinvar\Data\Data;
    use Tinvar\Tests\Data\Braced\Parts\Part as Item;

    $unused = 'x';
    $closure = function () use ($unused): string {
        return $unused;
    };

    final readonly class FirstHolder extends Data
    {
        /** @param list<Item> $items */
        public function __construct(public array $items) {}
    }
}

namespace Tinvar\Tests\Data\Braced\Second {
    use Tinvar\Data\Data;
    use Tinvar\Tests\Data\Braced\Parts\Other as Item;

    final readonly class SecondHolder extends Data
    {
        /** @param list<Item> $items */
        public function __construct(public array $items) {}
    }
}
