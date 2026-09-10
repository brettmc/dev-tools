<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Release;

class Repository
{
    public Project $upstream; //the monorepo that this repo is split from
    public Project $downstream; //the read-only downstream repo (split target)
    public ?Release $latestRelease = null; //latest release
    /**
     * @var array<Commit>
     */
    public array $commits = [];
    public Diff $diff;
}
