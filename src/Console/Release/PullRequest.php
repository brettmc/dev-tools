<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Release;

class PullRequest
{
    public int $id;
    public string $url;
    public string $title;
    public string $author;
}
