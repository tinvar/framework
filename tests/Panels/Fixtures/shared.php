<?php

declare(strict_types=1);

namespace Tinvar\Tests\Panels\Fixtures {
    use Tinvar\Attributes\Panel;
    use Tinvar\Attributes\Shared;
    use Tinvar\Auth\PanelContext;
    use Tinvar\Data\Data;
    use Tinvar\Data\SharedData;
    use Tinvar\Orm\Orm;

    final readonly class NoteData extends Data
    {
        public function __construct(public string $note, public string $panel, public string $tenant) {}
    }

    /** In no panel: every page reads it. */
    final class EveryoneShared implements SharedData
    {
        public function __construct(private readonly PanelContext $panels, private readonly Orm $orm) {}

        #[Shared]
        public function banner(): NoteData
        {
            return new NoteData('for everyone', $this->panels->currentOrNull() ?? '', $this->orm->tenant());
        }

        /** Shared once (ADR 0065): each time it is produced, the trace says so. */
        #[Shared(once: true, until: '1h', tags: ['notes'])]
        public function welcome(): NoteData
        {
            Trace::note('welcome');

            return new NoteData('welcome', $this->panels->currentOrNull() ?? '', $this->orm->tenant());
        }
    }

    #[Panel('admin')]
    final class StaffShared implements SharedData
    {
        #[Shared]
        public function staffNote(): NoteData
        {
            return new NoteData('for staff', 'admin', '');
        }
    }

    #[Panel('reseller')]
    final class ResellerShared implements SharedData
    {
        public function __construct(private readonly Orm $orm) {}

        #[Shared]
        public function resellerNote(): NoteData
        {
            return new NoteData('for resellers', 'reseller', $this->orm->tenant());
        }
    }

    #[Panel('nowhere')]
    final class UndefinedPanelShared implements SharedData
    {
        #[Shared]
        public function lost(): NoteData
        {
            return new NoteData('lost', 'nowhere', '');
        }
    }
}

namespace Tinvar\Tests\Panels\Fixtures\Admin {
    use Tinvar\Attributes\Shared;
    use Tinvar\Data\SharedData;
    use Tinvar\Tests\Panels\Fixtures\NoteData;

    /** In the admin panel by its namespace alone. */
    final class ReportsShared implements SharedData
    {
        #[Shared]
        public function reportNote(): NoteData
        {
            return new NoteData('for reports', 'admin', '');
        }
    }
}
