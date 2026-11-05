<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Spi;

final class SplitDependencyReport
{
    /**
     * @param list<SplitDependencyRow> $rows one per dependency on a split package
     * @param list<string> $external packages depended on that are not split from the monorepo
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $external,
    ) {
    }

    public function failed(): bool
    {
        foreach ($this->rows as $row) {
            if ($row->problems !== []) {
                return true;
            }
        }

        return false;
    }
}
