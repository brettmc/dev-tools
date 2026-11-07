<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Release;

use OpenTelemetry\DevTools\Console\Release\Commit;
use OpenTelemetry\DevTools\Console\Release\PullRequest;
use OpenTelemetry\DevTools\Console\Release\ReleaseNotes;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Console\Release\ReleaseNotes
 */
class ReleaseNotesTest extends TestCase
{
    private const DOWNSTREAM = 'opentelemetry-php/sem-conv';

    public function test_changelog_notes_replace_the_pull_request_list(): void
    {
        $notes = (new ReleaseNotes())->build(self::DOWNSTREAM, '1.44.0', '1.45.0', [$this->commit()], "### Added\n\n- New attributes.");

        $this->assertSame(
            "### Added\n\n- New attributes.\n\n**Full Changelog**: https://github.com/opentelemetry-php/sem-conv/compare/1.44.0...1.45.0",
            $notes
        );
    }

    public function test_pull_requests_are_listed_when_there_are_no_changelog_notes(): void
    {
        $notes = (new ReleaseNotes())->build(self::DOWNSTREAM, '1.44.0', '1.45.0', [$this->commit()], null);

        $this->assertSame(
            "What's Changed:\n* Add a thing by @octocat in [12](https://github.com/o/r/pull/12)\n\n**Full Changelog**: https://github.com/opentelemetry-php/sem-conv/compare/1.44.0...1.45.0",
            $notes
        );
    }

    public function test_first_release_without_changelog_notes_is_an_initial_release(): void
    {
        $this->assertSame('Initial release', (new ReleaseNotes())->build(self::DOWNSTREAM, null, '0.1.0', [], null));
    }

    public function test_first_release_uses_changelog_notes_when_there_are_some(): void
    {
        $this->assertSame('- First.', (new ReleaseNotes())->build(self::DOWNSTREAM, null, '0.1.0', [], '- First.'));
    }

    private function commit(): Commit
    {
        $commit = new Commit();
        $commit->sha = 'abc';
        $commit->message = 'Add a thing';
        $commit->pullRequest = new PullRequest();
        $commit->pullRequest->id = 12;
        $commit->pullRequest->title = 'Add a thing';
        $commit->pullRequest->author = 'octocat';
        $commit->pullRequest->url = 'https://github.com/o/r/pull/12';

        return $commit;
    }
}
