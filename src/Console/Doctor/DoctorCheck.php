<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor;

/**
 * One of `tinvar doctor`'s checks (ADR 0156): reads the application and
 * the environment it is handed in a DoctorContext -- never the process's
 * own, so a test hands it one -- and says everything it finds, never
 * stopping at the first. It runs what the framework runs at boot where
 * there is such a check, rather than a copy of it, so the doctor and the
 * boot cannot disagree.
 */
interface DoctorCheck
{
    /** What it checks, as a heading: `PHP and its extensions`. */
    public function title(): string;

    /**
     * Every finding, at least one: a check with nothing to say says it
     * passed, or why it was skipped.
     *
     * @return list<Finding>
     */
    public function run(DoctorContext $context): array;
}
