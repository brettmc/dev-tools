<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Behavior;

use DateTimeImmutable;
use DateTimeZone;
use OpenTelemetry\DevTools\Package\Php\PhpBranch;

trait UsesPhpStatesTrait
{
    /**
     * What https://www.php.net/releases/states.php lists, with branches that have no dates, and 8.6 released.
     */
    protected function phpStatesJson(): string
    {
        $state = static fn (string $released, string $securityEnd): array => [
            'state' => 'stable',
            'initial_release' => "{$released}T00:00:00+00:00",
            'active_support_end' => null,
            'security_support_end' => "{$securityEnd}T00:00:00+00:00",
        ];

        return (string) json_encode([
            '3' => ['3.0' => ['state' => null, 'initial_release' => null, 'active_support_end' => null, 'security_support_end' => '2000-10-20T00:00:00+00:00']],
            '8' => [
                '8.6' => $state('2026-11-19', '2030-12-31'),
                '8.5' => $state('2025-11-20', '2029-12-31'),
                '8.4' => $state('2024-11-21', '2028-12-31'),
                '8.3' => $state('2023-11-23', '2027-12-31'),
                '8.2' => $state('2022-12-08', '2026-12-31'),
                '8.1' => $state('2021-11-25', '2025-12-31'),
                '8.0' => $state('2020-11-26', '2023-11-26'),
            ],
        ]);
    }

    protected function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }

    protected function branch(string $version, string $released, string $securityEnd): PhpBranch
    {
        return new PhpBranch($version, $this->date($released), $this->date($securityEnd));
    }
}
