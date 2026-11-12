<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Php;

use DateInterval;
use DateTimeImmutable;
use RuntimeException;

/**
 * A PHP branch is supported until a number of months after its security support ends.
 *
 * Of the released branches that are not older than the project minimum, the minimum is to be
 * dropped once that date is within the notice period, and a branch that CI does not test is to be added.
 */
final class SupportPolicy
{
    public const DEFAULT_MONTHS = 12;
    public const DEFAULT_NOTICE_DAYS = 60;

    public function __construct(
        private readonly int $months = self::DEFAULT_MONTHS,
        private readonly int $noticeDays = self::DEFAULT_NOTICE_DAYS,
    ) {
    }

    public function supportedUntil(PhpBranch $branch): DateTimeImmutable
    {
        $end = $branch->securitySupportEnd;
        $until = $end->add(new DateInterval("P{$this->months}M"));
        //29 February plus a year is 1 March: keep to the last day of the month instead
        if ($until->format('d') !== $end->format('d')) {
            $until = $until->modify('last day of previous month');
        }

        return $until;
    }

    /**
     * @param list<PhpBranch> $branches
     */
    public function evaluate(array $branches, ProjectPhpVersions $project, DateTimeImmutable $today): SupportReport
    {
        if (!in_array($project->minimum, array_map(static fn (PhpBranch $b): string => $b->version, $branches), true)) {
            throw new RuntimeException("PHP {$project->minimum}, the minimum the project requires, is not a branch php.net lists with dates");
        }
        $rows = [];
        foreach ($branches as $branch) {
            if ($branch->released > $today || version_compare($branch->version, $project->minimum, '<')) {
                continue;
            }
            $until = $this->supportedUntil($branch);
            $tested = in_array($branch->version, $project->ci, true);
            $action = null;
            if ($branch->version === $project->minimum && $until->sub(new DateInterval("P{$this->noticeDays}D")) <= $today) {
                $action = SupportRow::DROP;
            } elseif (!$tested && $until >= $today) {
                $action = SupportRow::ADD;
            }
            $rows[] = new SupportRow($branch, $until, $tested, $action);
        }
        usort($rows, static fn (SupportRow $a, SupportRow $b): int => version_compare($a->branch->version, $b->branch->version));

        return new SupportReport($rows, $today);
    }
}
