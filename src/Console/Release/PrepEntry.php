<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Release;

use OpenTelemetry\DevTools\Package\Changelog\UnreleasedChanges;
use OpenTelemetry\DevTools\Package\Changelog\VersionSuggestion;

/**
 * What `release:prep` knows about one package while it works through them.
 */
class PrepEntry
{
    public ?string $latest = null;
    public ?string $file = null;
    public ?UnreleasedChanges $changes = null;
    public ?VersionSuggestion $suggestion = null;
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
