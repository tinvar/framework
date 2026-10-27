<?php

declare(strict_types=1);

/** A shared query in a file whose one namespace is braced: its imports are inside the braces. */

namespace Tinvar\Tests\Auth\Fixtures\CallGraphBraced {
    use Tinvar\Attributes\NoAuthorization;
    use Tinvar\Attributes\Query;
    use Tinvar\Tests\Auth\Fixtures\CallGraphGaps\Secret as Hidden;

    #[Query(name: 'braced.import', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
    final class ThroughBracedImport
    {
        public function __invoke(): int
        {
            return Hidden::who();
        }
    }
}
