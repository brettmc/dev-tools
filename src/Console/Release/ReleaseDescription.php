<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Release;

/**
 * The description of a monorepo's release pull request, built from `release:prep`, and the
 * markers in it that workflows read.
 *
 * The approval check, core's `release-approval.yml`, fails while the description has the APPROVAL
 * line. A tick is kept across rebuilds while the warnings it was given for are the same, which the
 * `<!-- breaking: <hash> -->` line under it records.
 */
final class ReleaseDescription
{
    public const APPROVAL = '- [ ] I have read the breaking changes';
    public const APPROVED = '- [x] I have read the breaking changes';
    public const STALE = '`main` has moved since this pull request was built, so it would release less than `main` holds. Rebuild it to bring the changes in. <!-- stale -->';
    private const DRY_RUN = '[DRY-RUN] no files were changed';

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
     * @param string $markdown the output of `release:prep --dry-run --format=markdown`
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
        $body .= implode('', array_filter(
            preg_split('/(?<=\n)/', $markdown) ?: [],
            static fn (string $line): bool => rtrim($line, "\n") !== self::DRY_RUN,
        ));

        $checks = self::checks($prep);
        if ($checks['warnings'] !== [] || $checks['constraints'] !== []) {
            $hash = self::hash($checks);
            $marker = "<!-- breaking: {$hash} -->";
            $lines = self::lines($previous);
            $box = in_array(self::APPROVED, $lines, true) && in_array($marker, $lines, true) ? self::APPROVED : self::APPROVAL;
            $body .= self::BREAKING . "{$box}\n{$marker}\n";
        }

        $body .= self::CHANGE;
        $body .= ($this->rebuildsOnEdit ? 'Saving rebuilds the pull request with them.' : 'Then rebuild it.') . "\n\n";
        $body .= Overrides::FENCE . "\n" . implode('', array_map(static fn (string $line): string => "{$line}\n", $overrides->lines)) . "```\n";

        return $body . self::MERGE;
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
     * The hash the workflow's bash took, `jq -c` and then `sha256sum`, so that a tick given before
     * the switch to this command survives it.
     *
     * @param array<mixed> $checks
     */
    private static function hash(array $checks): string
    {
        $json = json_encode($checks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return substr(hash('sha256', "{$json}\n"), 0, 16);
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return explode("\n", str_replace("\r", '', $text));
    }
}
