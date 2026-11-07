<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Release;

class Commit
{
    public string $sha;
    public string $message;
    public PullRequest $pullRequest;
}
