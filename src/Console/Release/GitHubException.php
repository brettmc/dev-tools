<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Release;

/**
 * A GitHub request that failed, or answered with something other than what was asked for. The code
 * is the HTTP status, or 0 when there was no usable response.
 */
class GitHubException extends \RuntimeException
{
}
