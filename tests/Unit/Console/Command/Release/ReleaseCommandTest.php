<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Release;

use Nyholm\Psr7\Response;
use OpenTelemetry\DevTools\Console\Command\Release\ReleaseCommand;
use OpenTelemetry\DevTools\Package\Git\GitRunner;
use OpenTelemetry\DevTools\Package\Spi\PackageDependencyReader;
use OpenTelemetry\DevTools\Package\Spi\ProviderDependencies;
use OpenTelemetry\DevTools\Package\Spi\ProviderDependency;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Release\ReleaseCommand
 */
class ReleaseCommandTest extends TestCase
{
    use UsesVfsTrait;

    private const CHANGELOG = "# Changelog\n\n## Unreleased\n\n## 1.45.0 - 2026-11-03\n\n### Added\n\n- New attributes ([#7]).\n\n## 1.44.0 - 2026-10-01\n\n### Fixed\n\n- Old.\n\n[#7]: https://example.com/7\n";

    /** @var list<array{method: string, url: string, body: string, authorization: string}> */
    private array $requests = [];
    private ?string $changelog = self::CHANGELOG;
    private string $latestTag = '1.44.0';
    /** @var array<string, array{0: string, 1: string, 2: ?string}> prefix, latest tag and changelog by project; sem-conv alone when empty */
    private array $splits = [];
    private ProviderDependencies $dependencies;
    private ?RuntimeException $readError = null;
    private ?string $head = 'abc123';
    /** @var list<string> */
    private array $changes = [];
    /** @var array<string, ?string> CHANGELOG.md of the split repository by project, null for none; the upstream one when unset */
    private array $splitChangelogs = [];
    /** @var list<string> the branch tip for each lookup in turn, the last repeating */
    private array $tips = ['abc123'];
    /** @var list<string> commit messages of the split since its latest release, oldest first */
    private array $splitMessages = ['Add attributes'];
    /** index in $splitMessages of the split commit with the checkout's tree, the newest when null */
    private ?int $splitMatch = null;
    /** whether gitsplit has pushed a commit with the checkout's tree */
    private bool $splitPushed = true;
    /** status of --sha compared to the branch on GitHub */
    private string $shaStatus = 'behind';
    /** @var list<array{0: string, 1: string}> sha and prefix of each tree read from the checkout */
    private array $treeReads = [];
    /** whether the monorepo has commits to each package since its latest release */
    private bool $upstreamCommits = true;
    /** @var array<string, int> status of the release request by project, 201 when unset */
    private array $releaseStatus = [];
    /** @var resource */
    private $out;
    /** @var resource */
    private $err;
    private string|false $environmentToken;

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
        file_put_contents($this->root->url() . '/.gitsplit.yml', 'splits: []');
        file_put_contents($this->root->url() . '/composer.json', '{"name":"open-telemetry/opentelemetry"}');
        $this->dependencies = new ProviderDependencies();
        $this->environmentToken = getenv('GITHUB_TOKEN');
        putenv('GITHUB_TOKEN');
    }

    #[\Override]
    public function tearDown(): void
    {
        putenv($this->environmentToken === false ? 'GITHUB_TOKEN' : "GITHUB_TOKEN={$this->environmentToken}");
    }

    public function test_token_is_read_from_the_environment_without_interaction(): void
    {
        putenv('GITHUB_TOKEN=from-env');

        $this->run_release('', ['--token' => null], false);

        $this->assertSame('Bearer from-env', $this->post()['authorization'] ?? null);
    }

    public function test_no_token_fails_before_calling_github_without_interaction(): void
    {
        $status = $this->run_release('', ['--token' => null], false);

        $this->assertSame(1, $status);
        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('No github token', $this->output());
    }

    public function test_no_interaction_releases_the_changelog_version_as_latest(): void
    {
        $status = $this->run_release('', [], false);

        $this->assertSame(0, $status);
        $this->assertSame('1.45.0', $this->created()['tag_name']);
        $this->assertSame('true', $this->created()['make_latest']);
        $this->assertFalse($this->created()['draft']);
    }

    public function test_no_interaction_does_not_make_a_release_off_main_the_latest(): void
    {
        $status = $this->run_release('', ['--branch' => '1.x'], false);

        $this->assertSame(0, $status);
        $this->assertSame('1.45.0', $this->created()['tag_name']);
        $this->assertSame('false', $this->created()['make_latest']);
    }

    public function test_no_interaction_skips_and_names_a_package_without_a_new_dated_section(): void
    {
        $this->latestTag = '1.45.0';

        $status = $this->run_release('', [], false);

        $this->assertSame(0, $status);
        $this->assertNull($this->post());
        $this->assertStringContainsString('[SKIP] opentelemetry-php/sem-conv has unreleased changes but no CHANGELOG.md section newer than 1.45.0', $this->output());
    }

    public function test_no_interaction_fails_on_a_diff_mismatch_before_tagging_anything(): void
    {
        $this->splits = [
            'sdk' => ['src/SDK', '1.15.0', self::section('1.16.0')],
            'api' => ['src/API', '1.10.0', self::section('1.11.0')],
        ];
        $this->composer('src/SDK', 'open-telemetry/sdk');
        $this->composer('src/API', 'open-telemetry/api');
        $this->splitMessages = ['Add attributes', 'Pushed straight to the split'];

        $status = $this->run_release('', ['--filter' => 'src/'], false);

        $this->assertSame(1, $status);
        $this->assertNull($this->post());
        $this->assertStringContainsString('Pushed straight to the split', $this->output());
    }

    public function test_no_interaction_dry_run_warns_about_a_diff_mismatch(): void
    {
        $this->splitMessages = ['Add attributes', 'Pushed straight to the split'];

        $status = $this->run_release('', ['--dry-run' => true], false);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Pushed straight to the split', $this->output());
        $this->assertStringContainsString('[DRY-RUN] https://api.github.com/repos/opentelemetry-php/sem-conv/releases', $this->output());
    }

    public function test_force_is_refused_without_interaction(): void
    {
        $status = $this->run_release('', ['--force' => true], false);

        $this->assertSame(1, $status);
        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('--force', $this->output());
    }

    public function test_failed_release_stops_the_run_without_interaction(): void
    {
        $this->failed_release_stops_the_run(false);
    }

    public function test_failed_release_stops_the_run_at_the_prompt(): void
    {
        $this->failed_release_stops_the_run(true);
    }

    private function failed_release_stops_the_run(bool $interactive): void
    {
        $this->splits = [
            'sdk' => ['src/SDK', '1.15.0', self::section('1.16.0')],
            'api' => ['src/API', '1.10.0', self::section('1.11.0')],
        ];
        $this->composer('src/SDK', 'open-telemetry/sdk', ['open-telemetry/api' => '^1.11']);
        $this->composer('src/API', 'open-telemetry/api');
        $this->releaseStatus = ['api' => 422];

        $status = $this->run_release(str_repeat("\n", 6), ['--filter' => 'src/'], $interactive);

        $this->assertSame(1, $status);
        $this->assertSame(['opentelemetry-php/api' => '1.11.0'], $this->tags());
        $this->assertStringContainsString('(422)', $this->output());
    }

    public function test_release_targets_the_newest_split_commit_with_the_tree_of_the_sha(): void
    {
        $this->splitMessages = ['Add attributes', 'Merged after the release', 'Also merged after'];
        $this->splitMatch = 0;

        $this->run_release("\n\n\n");

        $this->assertSame([['abc123', 'src/SemConv']], $this->treeReads);
        $this->assertSame('sem-conv-0', $this->created()['target_commitish']);
    }

    public function test_split_is_compared_up_to_the_split_commit(): void
    {
        $this->splitMessages = ['Add attributes', 'Merged after the release'];
        $this->splitMatch = 0;

        $status = $this->run_release('', [], false);

        $this->assertSame(0, $status);
        $this->assertContains('https://api.github.com/repos/opentelemetry-php/sem-conv/compare/1.44.0...sem-conv-0', array_column($this->requests, 'url'));
    }

    public function test_split_history_is_walked_from_the_latest_release(): void
    {
        $this->run_release('', ['--branch' => '1.x'], false);

        $this->assertContains(
            'https://api.github.com/repos/opentelemetry-php/sem-conv/commits?sha=1.x&per_page=100&since=2026-01-01T00:00:01Z',
            array_column($this->requests, 'url')
        );
    }

    public function test_release_stops_when_gitsplit_has_not_pushed_the_sha(): void
    {
        $this->splitPushed = false;

        $status = $this->run_release('', [], false);

        $this->assertSame(1, $status);
        $this->assertNull($this->post());
        $this->assertStringContainsString('opentelemetry-php/sem-conv main has no commit with the src/SemConv of abc123 yet. gitsplit may not have pushed it', $this->output());
    }

    public function test_dry_run_warns_when_gitsplit_has_not_pushed_the_sha(): void
    {
        $this->splitPushed = false;

        $status = $this->run_release('', ['--dry-run' => true], false);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('gitsplit may not have pushed it', $this->output());
        $this->assertStringContainsString('[DRY-RUN] https://api.github.com/repos/opentelemetry-php/sem-conv/releases', $this->output());
    }

    public function test_packages_without_changes_are_not_looked_up_in_the_split(): void
    {
        $this->splitPushed = false;
        $this->upstreamCommits = false;
        $this->splitMessages = [];

        $status = $this->run_release('', [], false);

        $this->assertSame(0, $status);
        $this->assertSame([], $this->treeReads);
    }

    public function test_sha_is_released_when_it_is_on_the_branch(): void
    {
        $status = $this->run_release('', ['--sha' => 'abc123'], false);

        $this->assertSame(0, $status);
        $urls = array_column($this->requests, 'url');
        $this->assertContains('https://api.github.com/repos/open-telemetry/opentelemetry-php/compare/main...abc123', $urls);
        $this->assertSame([], preg_grep('#/git/matching-refs/#', $urls));
        $this->assertSame('1.45.0', $this->created()['tag_name']);
    }

    public function test_sha_not_on_the_branch_fails(): void
    {
        $this->shaStatus = 'diverged';

        $status = $this->run_release('', ['--sha' => 'abc123'], false);

        $this->assertSame(1, $status);
        $this->assertNull($this->post());
        $this->assertStringContainsString('abc123 is not on open-telemetry/opentelemetry-php main', $this->output());
    }

    public function test_checkout_not_at_sha_fails_before_calling_github(): void
    {
        $status = $this->run_release('', ['--sha' => 'def456'], false);

        $this->assertSame(1, $status);
        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('is at abc123, but --sha is def456', $this->output());
    }

    public function test_upstream_commits_are_listed_up_to_the_sha(): void
    {
        $this->run_release('', ['--sha' => 'abc123'], false);

        $this->assertNotSame([], preg_grep('#/opentelemetry-php/commits\?path=src/SemConv&since=[^&]+&sha=abc123$#', array_column($this->requests, 'url')));
    }

    public function test_branch_that_moves_during_the_run_does_not_stop_it(): void
    {
        $this->splits = [
            'sdk' => ['src/SDK', '1.15.0', self::section('1.16.0')],
            'api' => ['src/API', '1.10.0', self::section('1.11.0')],
        ];
        $this->composer('src/SDK', 'open-telemetry/sdk', ['open-telemetry/api' => '^1.11']);
        $this->composer('src/API', 'open-telemetry/api');
        $this->tips = ['abc123', 'def789'];

        $status = $this->run_release('', ['--filter' => 'src/'], false);

        $this->assertSame(0, $status);
        $this->assertSame(['opentelemetry-php/api' => '1.11.0', 'opentelemetry-php/sdk' => '1.16.0'], $this->tags());
        $this->assertSame([['abc123', 'src/SDK'], ['abc123', 'src/API']], $this->treeReads);
        $this->assertCount(1, preg_grep('#/git/matching-refs/#', array_column($this->requests, 'url')));
    }

    public function test_rerun_after_a_partial_run_tags_only_the_rest(): void
    {
        $this->splits = [
            'sdk' => ['src/SDK', '1.15.0', self::section('1.16.0')],
            'api' => ['src/API', '1.11.0', self::section('1.11.0')],
        ];
        $this->composer('src/SDK', 'open-telemetry/sdk', ['open-telemetry/api' => '^1.11']);
        $this->composer('src/API', 'open-telemetry/api');

        $status = $this->run_release('', ['--filter' => 'src/'], false);

        $this->assertSame(0, $status);
        $this->assertSame(['opentelemetry-php/sdk' => '1.16.0'], $this->tags());
    }

    public function test_split_packages_and_changelogs_are_read_from_the_checkout(): void
    {
        $this->run_release('', [], false);

        $this->assertSame('1.45.0', $this->created()['tag_name']);
        $this->assertSame([], preg_grep('#raw\.githubusercontent\.com#', array_column($this->requests, 'url')));
    }

    public function test_core_without_path_fails_before_calling_github(): void
    {
        $this->run_release('', ['--path' => null]);

        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('--path', $this->output());
    }

    public function test_default_run_needs_path(): void
    {
        $this->run_release('', ['--path' => null, '--repo' => null]);

        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('--path', $this->output());
    }

    public function test_contrib_needs_path(): void
    {
        $this->run_release('', ['--path' => null, '--repo' => 'contrib']);

        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('--path', $this->output());
    }

    public function test_without_repo_the_checkout_is_released(): void
    {
        file_put_contents($this->root->url() . '/composer.json', '{"name":"open-telemetry/opentelemetry-php-contrib"}');

        $status = $this->run_release('', ['--repo' => null], false);

        $this->assertSame(0, $status);
        $this->assertSame('https://api.github.com/repos/open-telemetry/opentelemetry-php-contrib/git/matching-refs/heads/main', $this->requests[0]['url']);
        $this->assertSame('1.45.0', $this->created()['tag_name']);
    }

    public function test_invalid_repo_fails_before_calling_github(): void
    {
        $status = $this->run_release('', ['--repo' => 'nope', '--path' => null]);

        $this->assertSame(1, $status);
        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('Invalid source: nope', $this->output());
    }

    public function test_checkout_of_another_monorepo_fails_before_calling_github(): void
    {
        file_put_contents($this->root->url() . '/composer.json', '{"name":"open-telemetry/opentelemetry-php-contrib"}');

        $status = $this->run_release('');

        $this->assertSame(1, $status);
        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('not a checkout of open-telemetry/opentelemetry-php', $this->output());
    }

    public function test_contrib_checkout_is_used_for_contrib(): void
    {
        file_put_contents($this->root->url() . '/composer.json', '{"name":"open-telemetry/opentelemetry-php-contrib"}');

        $this->run_release("\n\n\n", ['--repo' => 'contrib']);

        $this->assertSame('1.45.0', $this->created()['tag_name']);
    }

    public function test_unreadable_git_checkout_fails_before_calling_github(): void
    {
        $this->head = null;

        $status = $this->run_release('');

        $this->assertSame(1, $status);
        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('fatal: not a git repository', $this->output());
    }

    public function test_uncommitted_changes_fail_before_calling_github(): void
    {
        $this->changes = [' M src/SemConv/composer.json'];

        $status = $this->run_release('');

        $this->assertSame(1, $status);
        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('uncommitted changes', $this->output());
        $this->assertStringContainsString(' M src/SemConv/composer.json', $this->output());
    }

    public function test_checkout_not_at_the_branch_tip_fails(): void
    {
        $this->head = 'old456';

        $status = $this->run_release('');

        $this->assertSame(1, $status);
        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('/repos/open-telemetry/opentelemetry-php/git/matching-refs/heads/main', $this->requests[0]['url']);
        $this->assertStringContainsString('old456', $this->output());
        $this->assertStringContainsString('abc123', $this->output());
    }

    public function test_dry_run_warns_about_an_out_of_date_checkout(): void
    {
        $this->head = 'old456';
        $this->changes = [' M src/SemConv/composer.json'];

        $status = $this->run_release("\n\n\n", ['--dry-run' => true]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('uncommitted changes', $this->output());
        $this->assertStringContainsString('old456', $this->output());
        $this->assertStringContainsString('[DRY-RUN]', $this->output());
    }

    public function test_dry_run_warns_about_an_unreadable_git_checkout(): void
    {
        $this->head = null;

        $status = $this->run_release("\n\n\n", ['--dry-run' => true]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('fatal: not a git repository', $this->output());
        $this->assertStringContainsString('[DRY-RUN]', $this->output());
    }

    public function test_release_stops_when_the_split_lacks_the_changelog_section(): void
    {
        $this->splitChangelogs = ['sem-conv' => self::section('1.44.0')];

        $status = $this->run_release("\n\n\n");

        $this->assertSame(1, $status);
        $this->assertNull($this->post());
        $this->assertStringContainsString('opentelemetry-php/sem-conv main has no 1.45.0 section in CHANGELOG.md yet', $this->output());
    }

    public function test_release_stops_when_the_split_has_no_changelog(): void
    {
        $this->splitChangelogs = ['sem-conv' => null];

        $status = $this->run_release("\n\n\n");

        $this->assertSame(1, $status);
        $this->assertNull($this->post());
        $this->assertStringContainsString('has no 1.45.0 section in CHANGELOG.md yet', $this->output());
    }

    public function test_split_changelog_is_read_through_the_api_at_the_split_commit(): void
    {
        $this->run_release("\n\n\n");

        $urls = array_column($this->requests, 'url');
        $this->assertContains('https://api.github.com/repos/opentelemetry-php/sem-conv/contents/CHANGELOG.md?ref=sem-conv-0', $urls);
    }

    public function test_dry_run_warns_when_the_split_lacks_the_changelog_section(): void
    {
        $this->splitChangelogs = ['sem-conv' => self::section('1.44.0')];

        $status = $this->run_release("\n\n\n", ['--dry-run' => true]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('has no 1.45.0 section in CHANGELOG.md yet', $this->output());
        $this->assertStringContainsString('[DRY-RUN] https://api.github.com/repos/opentelemetry-php/sem-conv/releases', $this->output());
    }

    public function test_split_is_not_checked_for_a_version_the_changelog_does_not_have(): void
    {
        $this->splitChangelogs = ['sem-conv' => self::section('1.44.0')];

        $this->run_release("1.46.0\n\n\n");

        $this->assertSame('1.46.0', $this->created()['tag_name']);
    }

    public function test_path_that_is_not_a_monorepo_fails_before_calling_github(): void
    {
        unlink($this->root->url() . '/.gitsplit.yml');

        $this->run_release('');

        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('not a monorepo checkout', $this->output());
    }

    public function test_unreadable_package_dependencies_fail_before_calling_github(): void
    {
        $this->readError = new RuntimeException('vfs://root/vendor/autoload.php not found, run `composer install` in vfs://root first');

        $this->run_release('');

        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('composer install', $this->output());
    }

    public function test_releases_follow_package_dependency_attributes(): void
    {
        $this->splits = [
            'sdk-configuration' => ['src/Config/SDK', '0.9.0', self::section('0.10.0')],
            'exporter-otlp' => ['src/Contrib/Otlp', '1.4.0', self::section('1.5.0')],
        ];
        $this->composer('src/Config/SDK', 'open-telemetry/sdk-configuration');
        $this->composer('src/Contrib/Otlp', 'open-telemetry/exporter-otlp');
        $this->dependencies = new ProviderDependencies([
            new ProviderDependency('Provider', 'open-telemetry/exporter-otlp', '^1.5', 'src/Config/SDK/Provider.php'),
        ]);

        $this->run_release(str_repeat("\n", 6), ['--filter' => 'src/']);

        $this->assertSame(
            ['opentelemetry-php/exporter-otlp' => '1.5.0', 'opentelemetry-php/sdk-configuration' => '0.10.0'],
            $this->tags()
        );
        $this->assertStringContainsString('Tag in this order: open-telemetry/exporter-otlp, open-telemetry/sdk-configuration', $this->output());
    }

    public function test_releases_follow_composer_requirements(): void
    {
        $this->splits = [
            'sdk' => ['src/SDK', '1.15.0', self::section('1.16.0')],
            'api' => ['src/API', '1.10.0', self::section('1.11.0')],
        ];
        $this->composer('src/SDK', 'open-telemetry/sdk', ['open-telemetry/api' => '^1.11']);
        $this->composer('src/API', 'open-telemetry/api');

        $this->run_release(str_repeat("\n", 6), ['--filter' => 'src/']);

        $this->assertSame(['opentelemetry-php/api', 'opentelemetry-php/sdk'], array_keys($this->tags()));
    }

    public function test_release_notes_come_from_the_changelog(): void
    {
        $this->run_release("\n\n\n");

        $this->assertStringContainsString('- New attributes ([#7]).', $this->created()['body']);
        $this->assertStringContainsString('[#7]: https://example.com/7', $this->created()['body']);
        $this->assertStringContainsString('compare/1.44.0...1.45.0', $this->created()['body']);
        $this->assertStringNotContainsString("What's Changed", $this->created()['body']);
    }

    public function test_blank_answer_takes_the_version_of_the_newest_dated_section(): void
    {
        $this->run_release("\n\n\n");

        $this->assertSame('1.45.0', $this->created()['tag_name']);
    }

    public function test_pull_requests_are_listed_when_the_changelog_has_no_section_for_the_version(): void
    {
        $this->run_release("1.46.0\n\n\n");

        $this->assertSame('1.46.0', $this->created()['tag_name']);
        $this->assertStringContainsString("What's Changed", $this->created()['body']);
        $this->assertStringContainsString('Add attributes by @octocat', $this->created()['body']);
        $this->assertStringContainsString('no section for 1.46.0', $this->output());
    }

    public function test_pull_requests_are_listed_when_the_package_has_no_changelog(): void
    {
        $this->changelog = null;

        $this->run_release("1.45.0\n\n\n");

        $this->assertStringContainsString("What's Changed", $this->created()['body']);
    }

    public function test_blank_answer_skips_when_the_changelog_is_not_newer_than_the_latest_release(): void
    {
        $this->latestTag = '1.45.0';

        $this->run_release("\n");

        $this->assertNull($this->post());
    }

    public function test_skip_answer_skips_even_with_a_suggestion(): void
    {
        $this->run_release("skip\n");

        $this->assertNull($this->post());
    }

    public function test_dry_run_creates_nothing(): void
    {
        $this->run_release("\n\n\n", ['--dry-run' => true]);

        $this->assertNull($this->post());
        $this->assertStringContainsString('[DRY-RUN]', $this->output());
    }

    public function test_json_format_dry_run_lists_the_releases_it_would_make_in_order(): void
    {
        $this->api_and_sdk();

        $status = $this->run_release('', ['--filter' => 'src/', '--dry-run' => true, '--format' => 'json'], false);

        $this->assertSame(0, $status);
        $this->assertNull($this->post());
        $this->assertSame([
            'dry_run' => true,
            'sha' => 'abc123',
            'releases' => [
                ['repository' => 'opentelemetry-php/api', 'version' => '1.11.0', 'target' => 'api-0', 'latest' => true, 'draft' => false, 'url' => null],
                ['repository' => 'opentelemetry-php/sdk', 'version' => '1.16.0', 'target' => 'sdk-0', 'latest' => true, 'draft' => false, 'url' => null],
            ],
            'skipped' => [],
            'completed' => true,
        ], $this->json());
        $this->assertStringContainsString('[DRY-RUN] https://api.github.com/repos/opentelemetry-php/api/releases', $this->errors());
    }

    public function test_json_format_lists_the_releases_made_with_their_urls(): void
    {
        $status = $this->run_release('', ['--format' => 'json'], false);

        $this->assertSame(0, $status);
        $this->assertFalse($this->json()['dry_run']);
        $this->assertSame([
            ['repository' => 'opentelemetry-php/sem-conv', 'version' => '1.45.0', 'target' => 'sem-conv-0', 'latest' => true, 'draft' => false, 'url' => 'https://github.com/x/y/releases/1'],
        ], $this->json()['releases']);
        $this->assertStringContainsString('[CREATED] opentelemetry-php/sem-conv 1.45.0', $this->errors());
    }

    public function test_json_format_lists_the_packages_skipped(): void
    {
        $this->latestTag = '1.45.0';

        $this->run_release('', ['--format' => 'json'], false);

        $this->assertSame([], $this->json()['releases']);
        $this->assertSame([
            ['repository' => 'opentelemetry-php/sem-conv', 'reason' => 'no CHANGELOG.md section newer than 1.45.0'],
        ], $this->json()['skipped']);
    }

    public function test_json_format_lists_a_package_skipped_at_the_prompt(): void
    {
        $this->run_release("skip\n", ['--format' => 'json']);

        $this->assertSame([
            ['repository' => 'opentelemetry-php/sem-conv', 'reason' => 'skipped at the prompt'],
        ], $this->json()['skipped']);
        $this->assertStringContainsString('enter new tag', $this->errors());
    }

    public function test_json_format_lists_a_package_skipped_for_its_differences(): void
    {
        $this->splitMessages = ['Add attributes', 'Pushed straight to the split'];

        $this->run_release("n\n", ['--format' => 'json']);

        $this->assertSame([
            ['repository' => 'opentelemetry-php/sem-conv', 'reason' => 'commits in the split that are not upstream'],
        ], $this->json()['skipped']);
    }

    public function test_json_format_after_a_failed_release_lists_what_was_released(): void
    {
        $this->api_and_sdk();
        $this->releaseStatus = ['sdk' => 422];

        $status = $this->run_release('', ['--filter' => 'src/', '--format' => 'json'], false);

        $this->assertSame(1, $status);
        $this->assertFalse($this->json()['completed']);
        $this->assertSame(['opentelemetry-php/api'], array_column($this->json()['releases'], 'repository'));
        $this->assertStringContainsString('(422)', $this->errors());
    }

    public function test_json_format_sends_errors_to_stderr(): void
    {
        $status = $this->run_release('', ['--format' => 'json', '--path' => null], false);

        $this->assertSame(1, $status);
        $this->assertSame([
            'dry_run' => false,
            'sha' => null,
            'releases' => [],
            'skipped' => [],
            'completed' => false,
        ], $this->json());
        $this->assertStringContainsString('release:run needs --path', $this->errors());
    }

    public function test_json_format_is_printed_when_the_run_stops_before_releasing(): void
    {
        $this->splitPushed = false;

        $status = $this->run_release('', ['--format' => 'json'], false);

        $this->assertSame(1, $status);
        $this->assertSame('abc123', $this->json()['sha']);
        $this->assertSame([], $this->json()['releases']);
        $this->assertFalse($this->json()['completed']);
        $this->assertStringContainsString('gitsplit may not have pushed it', $this->errors());
    }

    public function test_json_format_is_printed_without_a_token(): void
    {
        $status = $this->run_release('', ['--format' => 'json', '--token' => null], false);

        $this->assertSame(1, $status);
        $this->assertFalse($this->json()['completed']);
        $this->assertStringContainsString('No github token', $this->errors());
    }

    public function test_unknown_format_fails_before_calling_github(): void
    {
        $status = $this->run_release('', ['--format' => 'markdown'], false);

        $this->assertSame(1, $status);
        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('--format must be text or json', $this->output());
    }

    private function api_and_sdk(): void
    {
        $this->splits = [
            'sdk' => ['src/SDK', '1.15.0', self::section('1.16.0')],
            'api' => ['src/API', '1.10.0', self::section('1.11.0')],
        ];
        $this->composer('src/SDK', 'open-telemetry/sdk', ['open-telemetry/api' => '^1.11']);
        $this->composer('src/API', 'open-telemetry/api');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function run_release(string $answers, array $options = [], bool $interactive = true): int
    {
        $this->checkout();
        $this->out = fopen('php://memory', 'w+');
        $options +=['--token' => 'token', '--repo' => 'core', '--filter' => 'SemConv', '--path' => $this->root->url()];
        $input = new ArrayInput(array_filter($options, static fn (mixed $value): bool => $value !== null));
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $answers);
        rewind($stream);
        $input->setStream($stream);
        $input->setInteractive($interactive);
        $stdout = $this->out;
        $output = new class($stdout) extends ConsoleOutput {
            /** @param resource $memory */
            public function __construct(private $memory)
            {
                parent::__construct();
            }

            #[\Override]
            public function getStream()
            {
                return $this->memory;
            }

            #[\Override]
            protected function doWrite(string $message, bool $newline): void
            {
                fwrite($this->memory, $message . ($newline ? PHP_EOL : ''));
            }
        };
        $this->err = fopen('php://memory', 'w+');
        $output->setErrorOutput(new StreamOutput($this->err));

        return $this->command()->run($input, $output);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    private function splits(): array
    {
        return $this->splits ?: ['sem-conv' => ['src/SemConv', $this->latestTag, $this->changelog]];
    }

    /**
     * Write the splits and their changelogs into the checkout, unless a test has removed its
     * .gitsplit.yml to make it something other than a monorepo.
     */
    private function checkout(): void
    {
        $gitsplit = $this->root->url() . '/.gitsplit.yml';
        if (!is_file($gitsplit)) {
            return;
        }
        $yaml = "splits:\n";
        foreach ($this->splits() as $project => [$prefix, , $changelog]) {
            $yaml .= "  - prefix: \"{$prefix}\"\n    target: \"https://\${GH_TOKEN}@github.com/opentelemetry-php/{$project}.git\"\n";
            $dir = $this->root->url() . "/{$prefix}";
            is_dir($dir) || mkdir($dir, 0o777, true);
            $changelog === null || file_put_contents("{$dir}/CHANGELOG.md", $changelog);
        }
        file_put_contents($gitsplit, $yaml);
    }

    private function output(): string
    {
        rewind($this->out);

        return (string) stream_get_contents($this->out);
    }

    private function errors(): string
    {
        rewind($this->err);

        return (string) stream_get_contents($this->err);
    }

    /**
     * @return array<string, mixed> stdout, which must hold nothing but the JSON
     */
    private function json(): array
    {
        return json_decode($this->output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function created(): array
    {
        $post = $this->post();
        $this->assertNotNull($post, 'no release was created');

        return json_decode($post['body'], true);
    }

    /**
     * @return array<string, string> tag by downstream repository, in the order they were created
     */
    private function tags(): array
    {
        $tags = [];
        foreach ($this->requests as $request) {
            if ($request['method'] === 'POST' && preg_match('#/repos/([^/]+/[^/]+)/releases$#', $request['url'], $m)) {
                $tags[$m[1]] = json_decode($request['body'], true)['tag_name'];
            }
        }

        return $tags;
    }

    private static function section(string $version): string
    {
        return "# Changelog\n\n## Unreleased\n\n## {$version} - 2026-11-03\n\n### Added\n\n- New attributes ([#7]).\n\n[#7]: https://example.com/7\n";
    }

    /**
     * @param array<string, string> $require
     */
    private function composer(string $prefix, string $name, array $require = []): void
    {
        $dir = $this->root->url() . "/{$prefix}";
        is_dir($dir) || mkdir($dir, 0o777, true);
        file_put_contents("{$dir}/composer.json", json_encode(['name' => $name, 'require' => (object) $require]));
    }

    /**
     * @return array{method: string, url: string, body: string, authorization: string}|null
     */
    private function post(): ?array
    {
        foreach ($this->requests as $request) {
            if ($request['method'] === 'POST') {
                return $request;
            }
        }

        return null;
    }

    private function command(): ReleaseCommand
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request): Response {
            $url = (string) $request->getUri();
            $this->requests[] = [
                'method' => $request->getMethod(),
                'url' => $url,
                'body' => (string) $request->getBody(),
                'authorization' => $request->getHeaderLine('Authorization'),
            ];

            return $this->respond($request->getMethod(), $url);
        });

        $reader = $this->createMock(PackageDependencyReader::class);
        $reader->method('read')->willReturnCallback(fn (): ProviderDependencies => $this->readError !== null
            ? throw $this->readError
            : $this->dependencies);

        $git = $this->createMock(GitRunner::class);
        $git->method('head')->willReturnCallback(fn (): string => $this->head
            ?? throw new RuntimeException('fatal: not a git repository (or any of the parent directories): .git'));
        $git->method('changes')->willReturnCallback(fn (): array => $this->changes);
        $git->method('tree')->willReturnCallback(function (string $path, string $sha, string $prefix): string {
            $this->treeReads[] = [$sha, $prefix];

            return $this->head === null
                ? throw new RuntimeException('fatal: not a git repository (or any of the parent directories): .git')
                : "tree-{$prefix}";
        });

        return new ReleaseCommand($client, $reader, $git);
    }

    private function respond(string $method, string $url): Response
    {
        $json = static fn (mixed $data, int $status = 200): Response => new Response($status, [], json_encode($data));
        if ($method === 'POST') {
            preg_match('#/repos/[^/]+/([^/]+)/releases$#', $url, $m);
            $status = $this->releaseStatus[$m[1] ?? ''] ?? 201;

            return $status === 201
                ? $json(['html_url' => 'https://github.com/x/y/releases/1'], 201)
                : $json(['message' => 'Validation Failed'], $status);
        }
        if (preg_match('#/git/matching-refs/heads/(.+)$#', $url, $m) === 1) {
            $tip = count($this->tips) > 1 ? array_shift($this->tips) : $this->tips[0];

            return $json([['ref' => "refs/heads/{$m[1]}", 'object' => ['sha' => $tip]]]);
        }
        if (preg_match('#/open-telemetry/opentelemetry-php(-contrib)?/compare/#', $url) === 1) {
            return $json(['status' => $this->shaStatus]);
        }
        $match = $this->splitMatch ?? count($this->splitMessages) - 1;
        foreach ($this->splits() as $project => [$prefix, $latest, $changelog]) {
            $split = array_key_exists($project, $this->splitChangelogs) ? $this->splitChangelogs[$project] : $changelog;
            $response = match (true) {
                str_contains($url, "/{$project}/contents/CHANGELOG.md?ref=") => $split === null
                    ? new Response(404)
                    : $json(['encoding' => 'base64', 'content' => chunk_split(base64_encode($split), 60, "\n")]),
                str_contains($url, "/{$project}/releases/latest") => $json(['tag_name' => $latest, 'created_at' => '2026-01-01T00:00:00Z']),
                str_contains($url, "/{$project}/commits?sha=") => $json(array_reverse(array_map(
                    fn (string $message, int $i): array => [
                        'sha' => "{$project}-{$i}",
                        'commit' => ['message' => $message, 'tree' => ['sha' => $this->splitPushed && $i === $match ? "tree-{$prefix}" : "other-{$i}"]],
                    ],
                    $this->splitMessages,
                    array_keys($this->splitMessages),
                ))),
                preg_match("#/{$project}/compare/[^/]+\\.\\.\\.{$project}-(\\d+)$#", $url, $m) === 1 => $json(['commits' => array_map(
                    static fn (string $message): array => ['sha' => 'abc', 'commit' => ['message' => $message]],
                    array_slice($this->splitMessages, 0, (int) $m[1] + 1)
                )]),
                str_contains($url, "/{$project}/compare/") => $json(['commits' => array_map(
                    static fn (string $message): array => ['sha' => 'abc', 'commit' => ['message' => $message]],
                    $this->splitMessages
                )]),
                default => null,
            };
            if ($response !== null) {
                return $response;
            }
        }

        return match (true) {
            str_contains($url, '/commits/abc/pulls') => $json([['user' => ['login' => 'octocat'], 'html_url' => 'https://github.com/o/r/pull/7', 'number' => 7, 'title' => 'Add attributes']]),
            str_contains($url, '/commits?path=') => $json($this->upstreamCommits ? [['sha' => 'abc', 'commit' => ['message' => 'Add attributes']]] : []),
            default => new Response(404),
        };
    }
}
