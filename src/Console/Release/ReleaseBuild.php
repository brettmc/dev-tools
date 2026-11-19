<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Release;

/**
 * What a release pull request was built from, kept as trailers on its commit:
 *
 *     Built-On: <sha of main>
 *     Breaking-Changes: <hash of the warnings>
 *     Override: <package>=<version|skip>
 *
 * The description can be edited by anyone with write access, and an event's copy of it is out of
 * date by the time a queued run reads it. The commit is what was built. A rebase or Update branch
 * keeps the message but not the parent, so Built-On then no longer matches.
 */
final class ReleaseBuild
{
    private const MAIN = 'Built-On';
    private const BREAKING = 'Breaking-Changes';
    private const OVERRIDE = 'Override';

    public function __construct(
        public readonly string $main,
        public readonly ?string $breaking,
        public readonly Overrides $overrides,
    ) {
    }

    public function trailers(): string
    {
        $trailers = self::MAIN . ": {$this->main}\n";
        if ($this->breaking !== null) {
            $trailers .= self::BREAKING . ": {$this->breaking}\n";
        }
        foreach ($this->overrides->lines as $line) {
            $trailers .= self::OVERRIDE . ": {$line}\n";
        }

        return $trailers;
    }

    /**
     * The build recorded in the last paragraph of $message, or null when it holds none, or one
     * that is malformed.
     */
    public static function fromMessage(string $message): ?self
    {
        $paragraphs = preg_split('/\n{2,}/', trim(str_replace("\r", '', $message), "\n")) ?: [];
        $values = [self::MAIN => [], self::BREAKING => [], self::OVERRIDE => []];
        foreach (explode("\n", end($paragraphs) ?: '') as $line) {
            if (preg_match('/^([A-Za-z-]+): (.*)$/', $line, $matches) === 1 && isset($values[$matches[1]])) {
                $values[$matches[1]][] = $matches[2];
            }
        }
        [$main, $breaking] = [$values[self::MAIN], $values[self::BREAKING]];
        if (count($main) !== 1 || preg_match('/^[0-9a-f]{40}$/', $main[0]) !== 1) {
            return null;
        }
        if (count($breaking) > 1 || ($breaking !== [] && preg_match('/^[0-9a-f]{16}$/', $breaking[0]) !== 1)) {
            return null;
        }

        return new self($main[0], $breaking[0] ?? null, new Overrides($values[self::OVERRIDE]));
    }
}
