<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Release;

/**
 * The description of a monorepo's release pull request, built from `release:prep`, and the
 * markers in it that workflows read.
 *
 * `release:pr:check` takes the hash from the commit's {@see ReleaseBuild} trailers and requires
 * {@see approved()}. A tick is kept across rebuilds while the warnings it was given for are the
 * same, which the `<!-- breaking: <hash> -->` line under it records.
 */
final class ReleaseDescription
{
    public const APPROVAL = '- [ ] I have read the breaking changes';
    public const APPROVED = '- [x] I have read the breaking changes';
    public const STALE = '`main` has moved since this pull request was built, so it would release less than `main` holds. Rebuild it to bring the changes in. <!-- stale -->';
    /** A ticked box, typed by hand or clicked: GitHub renders each of these as ticked */
    private const TICKED = '/^ *[-*+] +\[[xX]\] +I have read the breaking changes *$/';

    private const INTRO = <<<'MD'
        To bring in later changes to `main`, rebuild this pull request: tick Open a release PR on the
        release dashboard issue, or run the Release PR workflow. Update branch doesn't rebuild it. A
        rebuild keeps the overrides and the tick below, and replaces the rest of this description.
        Fix changelog entries in a pull request to `main`, then rebuild.

        MD . "\n";
    private const BREAKING = <<<'MD'

        ### Breaking changes

        A package above records breaking changes, is a new major version, or breaks a constraint
        between packages. Read the warnings, then tick the box. The "Release approval" check fails
        until you do.

        MD . "\n";
    private const CHANGE = <<<'MD'

        ### Change a version

        Edit this description, with one `<package>=<version|skip>` per line in the block below. The
        package is the composer name or the split repository name.
        MD . "\n";
    private const MERGE = <<<'MD'

        ### What happens when you merge

        gitsplit pushes each package to its split repository. Then tag the packages in the order above
        with [release:run](https://github.com/opentelemetry-php/dev-tools#tag-a-release).
        MD . "\n";

    /**
     * @param string $repository the monorepo, `org/project`
     * @param bool $rebuildsOnEdit whether saving an edit to the description rebuilds the pull
     *        request. Edits to contrib's start no workflow, as the workflow is in core.
     */
    public function __construct(
        private readonly string $repository,
        private readonly string $sha,
        private readonly bool $rebuildsOnEdit,
    ) {
    }

    /**
     * @param string $markdown the report of `release:prep`, as `markdown` in its JSON
     * @param array<mixed> $prep the decoded output of `release:prep --format=json`
     * @param string $previous the description being replaced, empty for a new pull request
     */
    public function render(string $markdown, array $prep, string $previous, Overrides $overrides): string
    {
        $body = sprintf(
            "Releases [%1\$s](https://github.com/%1\$s) from `main` at [%2\$s](https://github.com/%1\$s/commit/%3\$s).\n",
            $this->repository,
            substr($this->sha, 0, 7),
            $this->sha,
        );
        $body .= self::INTRO;
        $body .= $markdown;

        $hash = self::breakingHash($prep);
        if ($hash !== null) {
            $box = self::approved($previous, $hash) ? self::APPROVED : self::APPROVAL;
            $body .= self::BREAKING . "{$box}\n" . self::marker($hash) . "\n";
        }

        $body .= self::CHANGE;
        $body .= ($this->rebuildsOnEdit ? 'Saving rebuilds the pull request with them.' : 'Then rebuild it.') . "\n\n";
        $body .= Overrides::FENCE . "\n" . implode('', array_map(static fn (string $line): string => "{$line}\n", $overrides->lines)) . "```\n";

        return $body . self::MERGE;
    }

    /**
     * The hash of what a tick is given for, or null when release:prep gave no warnings and no box
     * is needed.
     *
     * @param array<mixed> $prep the decoded output of `release:prep --format=json`
     */
    public static function breakingHash(array $prep): ?string
    {
        $checks = self::checks($prep);

        return $checks['warnings'] === [] && $checks['constraints'] === [] ? null : self::hash($checks);
    }

    /**
     * Whether $body has a ticked box and the marker for $hash, each as a whole line.
     */
    public static function approved(string $body, string $hash): bool
    {
        $lines = self::lines($body);

        return preg_grep(self::TICKED, $lines) !== [] && in_array(self::marker($hash), $lines, true);
    }

    /**
     * $body without the stale note, or the blank line that then starts it, for telling whether
     * anyone but the stale job has edited it.
     */
    public static function unmarked(string $body): string
    {
        $lines = array_values(array_filter(self::lines($body), static fn (string $line): bool => $line !== self::STALE));
        if (($lines[0] ?? null) === '') {
            array_shift($lines);
        }

        return rtrim(implode("\n", $lines), "\n");
    }

    public static function isStale(string $body): bool
    {
        return in_array(self::STALE, self::lines($body), true);
    }

    public static function markStale(string $body): string
    {
        return self::STALE . "\n\n" . rtrim(str_replace("\r", '', $body), "\n") . "\n";
    }

    /**
     * What a tick is given for: the packages with warnings, which release:prep gives for a forced
     * major as well as for breaking entries, and the constraint warnings.
     *
     * @param array<mixed> $prep
     * @return array{warnings: list<array{package: mixed, version: mixed, warnings: mixed}>, constraints: array<mixed>}
     */
    private static function checks(array $prep): array
    {
        $warnings = [];
        foreach (is_array($prep['packages'] ?? null) ? $prep['packages'] : [] as $package) {
            if (is_array($package) && ($package['warnings'] ?? []) !== []) {
                $warnings[] = ['package' => $package['package'] ?? null, 'version' => $package['version'] ?? null, 'warnings' => $package['warnings']];
            }
        }
        $constraints = $prep['constraint_warnings'] ?? [];

        return ['warnings' => $warnings, 'constraints' => is_array($constraints) ? $constraints : []];
    }

    /**
     * The first 16 hex digits of the sha256 of the compact JSON and a newline, as `jq -c | sha256sum`
     * gives. Open pull requests carry markers made that way, and a changed hash drops their tick.
     *
     * @param array<mixed> $checks
     */
    private static function hash(array $checks): string
    {
        $json = json_encode($checks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return substr(hash('sha256', "{$json}\n"), 0, 16);
    }

    private static function marker(string $hash): string
    {
        return "<!-- breaking: {$hash} -->";
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return explode("\n", str_replace("\r", '', $text));
    }
}
