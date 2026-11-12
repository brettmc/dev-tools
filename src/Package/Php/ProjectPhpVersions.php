<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Php;

/**
 * The PHP versions a project declares: the minimum it requires, and the versions its CI tests.
 */
final class ProjectPhpVersions
{
    /**
     * @param string $minimum lowest PHP branch the `php` requirement of composer.json allows, such as `8.2`
     * @param list<string> $ci PHP branches in the CI matrix
     */
    public function __construct(
        public readonly string $minimum,
        public readonly array $ci,
    ) {
    }
}
