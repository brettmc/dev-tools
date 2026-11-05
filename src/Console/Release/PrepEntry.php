<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Release;

use OpenTelemetry\DevTools\Package\Changelog\UnreleasedChanges;

/**
 * What `release:prep` knows about one package while it works through them.
 */
class PrepEntry
{
    public ?string $latest = null;
    public ?string $file = null;
    public ?UnreleasedChanges $changes = null;
    /** the package name, from its composer.json */
    public string $package = '';
    /** @var array<string, mixed> its decoded composer.json, empty when there is none */
    public array $composer = [];
    /** @var array<string, list<string>> `#[PackageDependency]` constraints of its providers, by package name */
    public array $spi = [];
    /** whether the package has changes to release but no default version, so a person has to choose */
    public bool $undecided = false;
    /** the version to release as, once chosen */
    public ?string $version = null;
    /** whether $version came from --set, and so needs no prompt */
    public bool $forced = false;
    public string $reason = '';
    /** @var list<string> */
    public array $warnings = [];

    public function __construct(public readonly Repository $repository)
    {
    }

    public function label(): string
    {
        return (string) $this->repository->downstream;
    }
}
