<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Php;

use DateTimeImmutable;

final class SupportReport
{
    /**
     * @param list<SupportRow> $rows released branches that are not older than the project minimum, oldest first
     */
    public function __construct(
        public readonly array $rows,
        public readonly DateTimeImmutable $today,
    ) {
    }

    /**
     * @return list<SupportRow> the branches the project supports, oldest first
     */
    public function supported(): array
    {
        return array_values(array_filter($this->rows, static fn (SupportRow $row): bool => $row->isSupported()));
    }

    /**
     * @return list<SupportRow> the branches to drop or add now
     */
    public function actions(): array
    {
        return array_values(array_filter($this->rows, static fn (SupportRow $row): bool => $row->action !== null));
    }
}
