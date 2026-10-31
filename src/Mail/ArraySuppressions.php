<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/** The suppression list in memory: for tests, and an application with no database. */
final class ArraySuppressions implements Suppressions
{
    /** @var array<string, Suppression> */
    public array $held = [];

    public function find(array $emails): array
    {
        $found = [];
        foreach ($emails as $email) {
            $key = strtolower($email);
            if (isset($this->held[$key])) {
                $found[$key] = $this->held[$key];
            }
        }

        return $found;
    }

    public function suppress(Suppression $suppression): void
    {
        $held = $this->held[$suppression->email] ?? null;
        if ($held === null || $suppression->reason->weight() >= $held->reason->weight()) {
            $this->held[$suppression->email] = $suppression;
        }
    }

    public function lift(string $email): void
    {
        unset($this->held[strtolower(trim($email))]);
    }
}
