<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Release;

use Nyholm\Psr7\Response;
use OpenTelemetry\DevTools\Console\Command\Release\ReleasePrepCommand;
use OpenTelemetry\DevTools\Console\Release\GitHub;
use OpenTelemetry\DevTools\Package\Git\GitRunner;
use OpenTelemetry\DevTools\Package\Spi\PackageDependencyReader;
use OpenTelemetry\DevTools\Package\Spi\ProviderDependencies;
use OpenTelemetry\DevTools\Package\Spi\ProviderDependency;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Release\ReleasePrepCommand
 */
class ReleasePrepCommandTest extends TestCase
{
    use UsesVfsTrait;

    private const DATE = '2026-11-03';
    private const FIXED = "# Changelog\n\n## Unreleased\n\n### Fixed\n\n- A bug.\n\n## 1.0.0 and earlier\n\nSee releases.\n";
    private const ADDED = "# Changelog\n\n## Unreleased\n\n### Added\n\n- A feature.\n\n## 1.0.0 and earlier\n\nSee releases.\n";
    private const BREAKING = "# Changelog\n\n## Unreleased\n\n### Breaking changes\n\n- Dropped X.\n\n## 1.0.0 and earlier\n\nSee releases.\n";
    private const EMPTY = "# Changelog\n\n## Unreleased\n\n## 1.0.0 and earlier\n\nSee releases.\n";

    /** @var array<string, string> latest tag by downstream project, eg api => 1.10.1 */
    private array $releases = [];
    private ProviderDependencies $dependencies;
    private RuntimeException|\Error|null $readError = null;
    private CommandTester $tester;
    /** @var list<string> URLs requested */
    private array $requests = [];
    /** tip of the upstream branch on GitHub, null for a failed lookup */
    private ?string $tip = 'abc123';
    /** status of the latest release lookups */
    private int $latestStatus = 200;
    private bool $tipIsLocal = true;
    private bool $tipIsAncestor = true;
    private ?RuntimeException $gitError = null;
    /** @var list<string> uncommitted changes, as `git status --porcelain` lines */
    private array $changes = [];
    /** @var list<array{0: string, 1: string, 2: string}> arguments of each isAncestor() call */
    private array $ancestorChecks = [];

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
        $this->releases = ['api' => '1.10.1', 'sdk' => '1.15.0'];
        $this->dependencies = new ProviderDependencies();
    }

    public function test_dry_run_reports_the_suggestion_and_writes_nothing(): void
    {
        $this->monorepo(['src/API' => ['api', self::ADDED]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--dry-run' => true]));
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('opentelemetry-php/api', $display);
        $this->assertStringContainsString('1.10.1', $display);
        $this->assertStringContainsString('1.11.0', $display);
        $this->assertStringContainsString('[DRY-RUN]', $display);
        $this->assertSame(self::ADDED, $this->changelog('src/API'));
    }

    public function test_no_interaction_accepts_the_suggestion_and_prepares_the_changelog(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);

        $this->assertSame(Command::SUCCESS, $this->prep([], ['interactive' => false]));
        $this->assertSame(
            "# Changelog\n\n## Unreleased\n\n## 1.10.2 - 2026-11-03\n\n### Fixed\n\n- A bug.\n\n## 1.0.0 and earlier\n\nSee releases.\n",
            $this->changelog('src/API')
        );
        $this->assertStringContainsString('src/API/CHANGELOG.md', $this->tester->getDisplay());
        $this->assertStringContainsString('release:run', $this->tester->getDisplay());
    }

    public function test_no_github_token_is_needed(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);

        $this->assertSame(Command::SUCCESS, $this->prep([], ['interactive' => false]));
    }

    public function test_blank_answer_accepts_the_suggestion(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);

        $this->assertSame(Command::SUCCESS, $this->prep([], ['inputs' => ['']]));
        $this->assertStringContainsString('## 1.10.2 - 2026-11-03', $this->changelog('src/API'));
    }

    public function test_skip_answer_leaves_the_changelog_alone(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);

        $this->assertSame(Command::SUCCESS, $this->prep([], ['inputs' => ['skip']]));
        $this->assertSame(self::FIXED, $this->changelog('src/API'));
    }

    public function test_typed_version_overrides_the_suggestion(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);

        $this->assertSame(Command::SUCCESS, $this->prep([], ['inputs' => ['2.0.0']]));
        $this->assertStringContainsString('## 2.0.0 - 2026-11-03', $this->changelog('src/API'));
    }

    public function test_set_overrides_without_prompting(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED], 'src/SDK' => ['sdk', self::FIXED]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--set' => ['sdk=1.20.0']], ['interactive' => false]));
        $this->assertStringContainsString('## 1.20.0 - 2026-11-03', $this->changelog('src/SDK'));
        $this->assertStringContainsString('## 1.10.2 - 2026-11-03', $this->changelog('src/API'));
    }

    public function test_set_accepts_the_composer_package_name(): void
    {
        $this->monorepo(['src/SDK' => ['sdk', self::FIXED]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--set' => ['open-telemetry/sdk=1.20.0']], ['interactive' => false]));
        $this->assertStringContainsString('## 1.20.0 - 2026-11-03', $this->changelog('src/SDK'));
    }

    public function test_set_accepts_the_composer_name_from_the_package(): void
    {
        $this->monorepo(['src/Instrumentation/Laravel' => ['opentelemetry-auto-laravel', self::FIXED]]);
        file_put_contents(
            $this->root->url() . '/src/Instrumentation/Laravel/composer.json',
            '{"name":"open-telemetry/opentelemetry-auto-laravel"}'
        );
        $this->releases = ['opentelemetry-auto-laravel' => '1.0.0'];

        $this->assertSame(Command::SUCCESS, $this->prep(['--set' => ['open-telemetry/opentelemetry-auto-laravel=1.2.0']], ['interactive' => false]));
        $this->assertStringContainsString('## 1.2.0 - 2026-11-03', $this->changelog('src/Instrumentation/Laravel'));
    }

    public function test_composer_name_comes_from_composer_json_not_the_repository_name(): void
    {
        $this->monorepo(['src/Instrumentation/Laravel' => ['contrib-laravel', self::FIXED]]);
        file_put_contents(
            $this->root->url() . '/src/Instrumentation/Laravel/composer.json',
            '{"name":"open-telemetry/opentelemetry-auto-laravel"}'
        );
        $this->releases = ['contrib-laravel' => '1.0.0'];

        $this->assertSame(Command::FAILURE, $this->prep(['--set' => ['open-telemetry/contrib-laravel=1.2.0']], ['interactive' => false]));
        $this->assertStringContainsString('open-telemetry/contrib-laravel', $this->tester->getDisplay());
    }

    public function test_set_with_a_bad_version_fails_before_writing(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);

        $this->assertSame(Command::FAILURE, $this->prep(['--set' => ['api=banana']], ['interactive' => false]));
        $this->assertStringContainsString('banana', $this->tester->getDisplay());
        $this->assertSame(self::FIXED, $this->changelog('src/API'));
    }

    public function test_set_skip_leaves_the_changelog_and_alias_alone_and_reports_it(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED], 'src/SDK' => ['sdk', self::FIXED]]);
        $this->alias('src/SDK', 'open-telemetry/sdk', '1.15.x-dev');

        $this->assertSame(Command::SUCCESS, $this->prep(['--set' => ['sdk=skip']], ['interactive' => false]));
        $this->assertSame('1.15.x-dev', $this->branchAlias('src/SDK'));
        $this->assertStringNotContainsString('## 1.15.1', $this->changelog('src/SDK'));
        $this->assertStringContainsString('## 1.10.2 - 2026-11-03', $this->changelog('src/API'));
        $this->assertMatchesRegularExpression('/sdk\s*\|\s*1\.15\.0\s*\|\s*\|\s*skipped by --set/', $this->tester->getDisplay());
    }

    public function test_set_skip_is_not_asked_about(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--set' => ['api=skip']], ['inputs' => []]));
        $this->assertStringNotContainsString('Version to release', $this->tester->getDisplay());
        $this->assertSame(self::FIXED, $this->changelog('src/API'));
    }

    public function test_set_for_an_unknown_package_fails(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);

        $this->assertSame(Command::FAILURE, $this->prep(['--set' => ['nope=1.0.0']], ['interactive' => false]));
        $this->assertStringContainsString('nope', $this->tester->getDisplay());
        $this->assertSame(self::FIXED, $this->changelog('src/API'));
    }

    public function test_package_without_a_changelog_is_skipped_and_reported(): void
    {
        $this->monorepo(['src/API' => ['api', null]]);

        $this->assertSame(Command::SUCCESS, $this->prep([], ['interactive' => false]));
        $this->assertStringContainsString('no CHANGELOG.md', $this->tester->getDisplay());
    }

    public function test_relative_path_reports_a_package_without_a_changelog(): void
    {
        $this->monorepo(['src/API' => ['api', null]]);
        $base = sys_get_temp_dir() . '/release-prep-' . bin2hex(random_bytes(4));
        mkdir("{$base}/monorepo/src/API", 0o777, true);
        copy($this->root->url() . '/.gitsplit.yml', "{$base}/monorepo/.gitsplit.yml");
        copy($this->root->url() . '/composer.json', "{$base}/monorepo/composer.json");
        $cwd = (string) getcwd();
        chdir($base);

        try {
            $status = $this->prep(['--path' => 'monorepo'], ['interactive' => false]);
        } finally {
            chdir($cwd);
            exec('rm -rf ' . escapeshellarg($base));
        }

        $this->assertStringNotContainsString('resolves outside', $this->tester->getDisplay());
        $this->assertStringContainsString('no CHANGELOG.md', $this->tester->getDisplay());
        $this->assertSame(Command::SUCCESS, $status);
    }

    public function test_package_with_nothing_unreleased_is_not_released(): void
    {
        $this->monorepo(['src/API' => ['api', self::EMPTY]]);

        $this->assertSame(Command::SUCCESS, $this->prep([], ['interactive' => false]));
        $this->assertStringContainsString('no unreleased changes', $this->tester->getDisplay());
        $this->assertSame(self::EMPTY, $this->changelog('src/API'));
    }

    public function test_unknown_heading_fails_and_names_the_heading(): void
    {
        $bad = "# Changelog\n\n## Unreleased\n\n### Housekeeping\n\n- Tidied.\n";
        $this->monorepo(['src/API' => ['api', $bad]]);

        $this->assertSame(Command::FAILURE, $this->prep([], ['interactive' => false]));
        $this->assertStringContainsString('Housekeeping', $this->tester->getDisplay());
        $this->assertSame($bad, $this->changelog('src/API'));
    }

    public function test_breaking_entries_are_listed_and_still_a_minor(): void
    {
        $this->monorepo(['src/API' => ['api', self::BREAKING]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--dry-run' => true]));
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('1.11.0', $display);
        $this->assertStringContainsString('Dropped X.', $display);
    }

    public function test_forced_major_without_breaking_entries_warns(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--set' => ['api=2.0.0']], ['interactive' => false]));
        $this->assertStringContainsString('major', $this->tester->getDisplay());
        $this->assertStringContainsString('## 2.0.0 - 2026-11-03', $this->changelog('src/API'));
    }

    public function test_forced_major_with_breaking_entries_does_not_warn_about_missing_entries(): void
    {
        $this->monorepo(['src/API' => ['api', self::BREAKING]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--set' => ['api=2.0.0']], ['interactive' => false]));
        $this->assertStringNotContainsString('no breaking', $this->tester->getDisplay());
    }

    public function test_pre_release_latest_has_no_default_when_not_interactive(): void
    {
        $this->releases = ['api' => '1.0.0RC1'];
        $this->monorepo(['src/API' => ['api', self::ADDED]]);

        $this->assertSame(Command::SUCCESS, $this->prep([], ['interactive' => false]));
        $this->assertStringContainsString('pre-release', $this->tester->getDisplay());
        $this->assertSame(self::ADDED, $this->changelog('src/API'));
    }

    public function test_pre_release_latest_prompts_without_a_default(): void
    {
        $this->releases = ['api' => '1.0.0RC1'];
        $this->monorepo(['src/API' => ['api', self::ADDED]]);

        $this->assertSame(Command::SUCCESS, $this->prep([], ['inputs' => ['1.0.0']]));
        $this->assertStringContainsString('## 1.0.0 - 2026-11-03', $this->changelog('src/API'));
    }

    public function test_undecided_packages_are_listed_after_the_table(): void
    {
        $this->releases = ['api' => '1.0.0RC1', 'sdk' => '1.15.0'];
        $this->monorepo(['src/API' => ['api', self::ADDED], 'src/SDK' => ['sdk', self::FIXED]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--dry-run' => true]));
        $this->assertStringContainsString(
            "\nNo version suggested, choose one with --set=<package>=<version>:\n- opentelemetry-php/api\n",
            $this->tester->getDisplay()
        );
        $this->assertStringNotContainsString('- opentelemetry-php/sdk', $this->tester->getDisplay());
    }

    public function test_undecided_packages_are_listed_in_markdown(): void
    {
        $this->releases = ['api' => '1.0.0RC1'];
        $this->monorepo(['src/API' => ['api', self::ADDED]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--dry-run' => true, '--format' => 'markdown']));
        $this->assertStringContainsString(
            "\n**No version suggested**, choose one with `--set=<package>=<version>`:\n- opentelemetry-php/api\n",
            $this->tester->getDisplay()
        );
    }

    public function test_no_undecided_list_when_every_package_has_a_suggestion(): void
    {
        $this->monorepo(['src/API' => ['api', self::ADDED]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--dry-run' => true]));
        $this->assertStringNotContainsString('No version suggested', $this->tester->getDisplay());
    }

    public function test_json_format_marks_a_package_undecided(): void
    {
        $this->releases = ['api' => '1.0.0RC1', 'sdk' => '1.15.0'];
        $this->monorepo(['src/API' => ['api', self::ADDED], 'src/SDK' => ['sdk', self::FIXED]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--format' => 'json'], ['interactive' => false, 'capture_stderr_separately' => true]));

        $json = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([true, false], array_column($json['packages'], 'undecided'));
        $this->assertSame([null, '1.15.1'], array_column($json['packages'], 'version'));
    }

    public function test_json_format_package_chosen_at_the_prompt_is_not_undecided(): void
    {
        $this->releases = ['api' => '1.0.0RC1'];
        $this->monorepo(['src/API' => ['api', self::ADDED]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--format' => 'json'], ['inputs' => ['1.0.0'], 'capture_stderr_separately' => true]));

        $json = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([false], array_column($json['packages'], 'undecided'));
        $this->assertSame(['1.0.0'], array_column($json['packages'], 'version'));
    }

    public function test_package_with_no_release_is_skipped(): void
    {
        $this->releases = [];
        $this->monorepo(['src/API' => ['api', self::ADDED]]);

        $this->assertSame(Command::SUCCESS, $this->prep([], ['interactive' => false]));
        $this->assertStringContainsString('no release', $this->tester->getDisplay());
        $this->assertSame(self::ADDED, $this->changelog('src/API'));
    }

    public function test_split_prefix_outside_the_checkout_is_refused(): void
    {
        $this->monorepo(['../outside' => ['api', self::FIXED]]);

        $this->assertSame(Command::FAILURE, $this->prep([], ['interactive' => false]));
        $this->assertStringContainsString('resolves outside', $this->tester->getDisplay());
    }

    public function test_filter_limits_the_packages(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED], 'src/SDK' => ['sdk', self::FIXED]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--filter' => 'SDK'], ['interactive' => false]));
        $this->assertStringContainsString('## 1.15.1', $this->changelog('src/SDK'));
        $this->assertSame(self::FIXED, $this->changelog('src/API'));
    }

    public function test_bad_date_fails(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);

        $this->assertSame(Command::FAILURE, $this->prep(['--date' => '3 November'], ['interactive' => false]));
        $this->assertSame(self::FIXED, $this->changelog('src/API'));
    }

    public function test_path_that_is_not_a_monorepo_fails(): void
    {
        $this->assertSame(Command::FAILURE, $this->prep([], ['interactive' => false]));
        $this->assertStringContainsString('is not a monorepo checkout', $this->tester->getDisplay());
    }

    public function test_split_without_a_target_fails_naming_it(): void
    {
        $this->monorepo([]);
        file_put_contents($this->root->url() . '/.gitsplit.yml', "splits:\n  - prefix: \"src/API\"\n");

        $this->assertSame(Command::FAILURE, $this->prep([], ['interactive' => false]));
        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('.gitsplit.yml split 1 has no target', $this->tester->getDisplay());
    }

    public function test_path_that_does_not_exist_fails_naming_it(): void
    {
        $this->assertSame(Command::FAILURE, $this->prep(['--path' => '/nowhere/monorepo'], ['interactive' => false]));
        $this->assertStringContainsString('--path /nowhere/monorepo is not a directory', $this->tester->getDisplay());
    }

    public function test_markdown_format_prints_a_table_for_a_pull_request_body(): void
    {
        $this->monorepo(['src/API' => ['api', self::BREAKING]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--dry-run' => true, '--format' => 'markdown']));
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('| Package               | Latest | Suggested | Reason                    |', $display);
        $this->assertStringContainsString('| opentelemetry-php/api | 1.10.1 | 1.11.0    | breaking changes recorded |', $display);
        $this->assertStringContainsString('- Dropped X.', $display);
    }

    public function test_json_format_prints_the_packages_and_order_on_stdout(): void
    {
        $this->monorepo(['src/API' => ['api', self::ADDED], 'src/SDK' => ['sdk', self::BREAKING], 'src/Context' => ['context', self::EMPTY]]);
        $this->composer('src/API', 'open-telemetry/api', []);
        $this->composer('src/SDK', 'open-telemetry/sdk', ['open-telemetry/api' => '^1.11']);
        $this->releases['context'] = '1.4.0';

        $this->assertSame(Command::SUCCESS, $this->prep(['--dry-run' => true, '--format' => 'json'], ['capture_stderr_separately' => true]));

        $this->assertSame([
            'packages' => [
                ['package' => 'open-telemetry/api', 'repository' => 'opentelemetry-php/api', 'latest' => '1.10.1', 'version' => '1.11.0', 'reason' => 'new features', 'warnings' => [], 'undecided' => false],
                ['package' => 'open-telemetry/sdk', 'repository' => 'opentelemetry-php/sdk', 'latest' => '1.15.0', 'version' => '1.16.0', 'reason' => 'breaking changes recorded', 'warnings' => ['Dropped X.'], 'undecided' => false],
                ['package' => 'open-telemetry/context', 'repository' => 'opentelemetry-php/context', 'latest' => '1.4.0', 'version' => null, 'reason' => 'no unreleased changes', 'warnings' => [], 'undecided' => false],
            ],
            'order' => ['open-telemetry/api', 'open-telemetry/sdk'],
            'constraint_warnings' => [],
            'release' => true,
            'completed' => true,
        ], json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('[DRY-RUN] no files were changed', $this->tester->getErrorOutput());
    }

    public function test_json_format_with_nothing_to_release(): void
    {
        $this->monorepo(['src/API' => ['api', self::EMPTY]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--format' => 'json'], ['interactive' => false, 'capture_stderr_separately' => true]));

        $json = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($json['release']);
        $this->assertNull($json['packages'][0]['version']);
        $this->assertStringContainsString('Nothing to release.', $this->tester->getErrorOutput());
    }

    public function test_json_format_reports_the_versions_chosen(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED], 'src/SDK' => ['sdk', self::FIXED]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--format' => 'json', '--set' => ['sdk=skip', 'api=1.11.0']], ['interactive' => false, 'capture_stderr_separately' => true]));

        $json = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['1.11.0', null], array_column($json['packages'], 'version'));
        $this->assertSame(['set by --set', 'skipped by --set'], array_column($json['packages'], 'reason'));
        $this->assertTrue($json['release']);
    }

    public function test_json_format_is_printed_for_a_bad_option(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);

        $this->assertSame(Command::FAILURE, $this->prep(['--format' => 'json', '--date' => '3 November'], ['interactive' => false, 'capture_stderr_separately' => true]));

        $this->assertSame([
            'packages' => [],
            'order' => [],
            'constraint_warnings' => [],
            'release' => false,
            'completed' => false,
        ], json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('--date must be YYYY-MM-DD', $this->tester->getErrorOutput());
    }

    public function test_json_format_is_printed_for_an_unknown_set_package(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);

        $this->assertSame(Command::FAILURE, $this->prep(['--format' => 'json', '--set' => ['nope=1.0.0']], ['interactive' => false, 'capture_stderr_separately' => true]));

        $this->assertFalse(json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['completed']);
        $this->assertStringContainsString('nope', $this->tester->getErrorOutput());
    }

    public function test_json_format_lists_the_packages_when_one_fails(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED], 'src/SDK' => ['sdk', "# Changelog\n\n## Unreleased\n\n### Housekeeping\n\n- Tidied.\n"]]);

        $this->assertSame(Command::FAILURE, $this->prep(['--format' => 'json'], ['interactive' => false, 'capture_stderr_separately' => true]));

        $json = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($json['completed']);
        $this->assertSame(['open-telemetry/api', 'open-telemetry/sdk'], array_column($json['packages'], 'package'));
        $this->assertStringContainsString('Housekeeping', $this->tester->getErrorOutput());
    }

    public function test_json_format_is_printed_when_the_prep_crashes(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);
        $this->readError = new \TypeError('Cannot assign null to property');

        $this->assertSame(Command::FAILURE, $this->prep(['--format' => 'json'], ['interactive' => false, 'capture_stderr_separately' => true]));

        $this->assertFalse(json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['completed']);
        $this->assertStringContainsString('Cannot assign null to property', $this->tester->getErrorOutput());
    }

    public function test_json_format_is_printed_when_a_lookup_throws(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);
        $this->latestStatus = 500;

        $this->assertSame(Command::FAILURE, $this->prep(['--format' => 'json'], ['interactive' => false, 'capture_stderr_separately' => true]));

        $this->assertFalse(json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['completed']);
        $this->assertStringContainsString('releases/latest: 500', $this->tester->getErrorOutput());
    }

    public function test_unknown_format_fails(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);

        $this->assertSame(Command::FAILURE, $this->prep(['--format' => 'yaml'], ['interactive' => false]));
        $this->assertStringContainsString('--format must be text, markdown or json', $this->tester->getDisplay());
    }

    public function test_nested_bullets_in_a_warning_stay_nested(): void
    {
        $nested = "# Changelog\n\n## Unreleased\n\n### Breaking changes\n\n- Dropped:\n  - X\n  - Y\n\n## 1.0.0 and earlier\n\nSee releases.\n";
        $this->monorepo(['src/API' => ['api', $nested]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--dry-run' => true, '--format' => 'markdown']));
        $this->assertStringContainsString("- Dropped:\n  - X\n  - Y\n", $this->tester->getDisplay());
    }

    public function test_tag_order_is_printed_when_a_package_needs_anothers_new_version(): void
    {
        $this->monorepo(['src/API' => ['api', self::ADDED], 'src/SDK' => ['sdk', self::ADDED]]);
        $this->composer('src/API', 'open-telemetry/api', []);
        $this->composer('src/SDK', 'open-telemetry/sdk', ['open-telemetry/api' => '^1.11']);

        $this->assertSame(Command::SUCCESS, $this->prep(['--dry-run' => true]));
        $this->assertStringContainsString('Tag in this order: open-telemetry/api, open-telemetry/sdk', $this->tester->getDisplay());
    }

    public function test_forced_major_warns_about_a_dependent_whose_constraint_excludes_it(): void
    {
        $this->releases['sdk'] = '1.16.0';
        $this->monorepo(['src/SDK' => ['sdk', self::BREAKING], 'src/Exporter' => ['exporter', self::EMPTY]]);
        $this->releases['exporter'] = '1.4.0';
        $this->composer('src/SDK', 'open-telemetry/sdk', []);
        $this->composer('src/Exporter', 'open-telemetry/exporter', ['open-telemetry/sdk' => '^1.16']);

        $this->assertSame(Command::SUCCESS, $this->prep(['--set' => ['sdk=2.0.0'], '--dry-run' => true]));
        $this->assertStringContainsString('open-telemetry/exporter requires open-telemetry/sdk ^1.16, which excludes 2.0.0', $this->tester->getDisplay());
    }

    public function test_no_tag_order_without_dependencies_between_packages(): void
    {
        $this->monorepo(['src/API' => ['api', self::ADDED]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--dry-run' => true]));
        $this->assertStringNotContainsString('Tag in this order', $this->tester->getDisplay());
    }

    public function test_tag_order_follows_package_dependency_attributes(): void
    {
        $this->releases = ['configuration' => '0.9.0', 'exporter' => '1.4.0'];
        $this->monorepo(['src/Config/SDK' => ['configuration', self::ADDED], 'src/Contrib/Otlp' => ['exporter', self::ADDED]]);
        $this->composer('src/Config/SDK', 'open-telemetry/sdk-configuration', []);
        $this->composer('src/Contrib/Otlp', 'open-telemetry/exporter-otlp', []);
        $this->dependencies = new ProviderDependencies([
            new ProviderDependency('Provider', 'open-telemetry/exporter-otlp', '^1.5', 'src/Config/SDK/Provider.php'),
        ]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--dry-run' => true]));
        $this->assertStringContainsString(
            'Tag in this order: open-telemetry/exporter-otlp, open-telemetry/sdk-configuration',
            $this->tester->getDisplay()
        );
    }

    public function test_package_dependency_attributes_that_cannot_be_read_fail_before_prompting(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);
        $this->readError = new RuntimeException('vfs://root/vendor/autoload.php not found, run `composer install` in vfs://root first');

        $this->assertSame(Command::FAILURE, $this->prep([], ['inputs' => ['']]));
        $this->assertStringContainsString('composer install', $this->tester->getDisplay());
        $this->assertStringNotContainsString('Version to release', $this->tester->getDisplay());
        $this->assertSame(self::FIXED, $this->changelog('src/API'));
    }

    public function test_alias_moves_to_the_new_minor(): void
    {
        $this->monorepo(['src/API' => ['api', self::ADDED]]);
        $this->alias('src/API', 'open-telemetry/api', '1.10.x-dev');

        $this->assertSame(Command::SUCCESS, $this->prep([], ['interactive' => false]));
        $this->assertSame('1.11.x-dev', $this->branchAlias('src/API'));
        $this->assertStringContainsString('open-telemetry/api: 1.10.x-dev -> 1.11.x-dev', $this->tester->getDisplay());
    }

    public function test_alias_that_covers_the_new_version_is_left_alone(): void
    {
        $this->monorepo(['src/API' => ['api', self::ADDED]]);
        $this->alias('src/API', 'open-telemetry/api', '1.x-dev');

        $this->assertSame(Command::SUCCESS, $this->prep([], ['interactive' => false]));
        $this->assertSame('1.x-dev', $this->branchAlias('src/API'));
        $this->assertStringNotContainsString('1.x-dev ->', $this->tester->getDisplay());
    }

    public function test_alias_ahead_of_the_chosen_version_is_lowered_with_a_warning(): void
    {
        $this->releases = ['configuration' => '0.9.0'];
        $this->monorepo(['src/Config/SDK' => ['configuration', self::ADDED]]);
        $this->alias('src/Config/SDK', 'open-telemetry/sdk-configuration', '1.0.x-dev');

        $this->assertSame(Command::SUCCESS, $this->prep(['--set' => ['configuration=0.10.0']], ['interactive' => false]));
        $this->assertSame('0.10.x-dev', $this->branchAlias('src/Config/SDK'));
        $this->assertStringContainsString('open-telemetry/sdk-configuration: 1.0.x-dev -> 0.10.x-dev, lowered', $this->tester->getDisplay());
    }

    public function test_package_skipped_at_the_prompt_keeps_its_alias(): void
    {
        $this->monorepo(['src/API' => ['api', self::ADDED]]);
        $this->alias('src/API', 'open-telemetry/api', '1.10.x-dev');

        $this->assertSame(Command::SUCCESS, $this->prep([], ['inputs' => ['skip']]));
        $this->assertSame('1.10.x-dev', $this->branchAlias('src/API'));
    }

    public function test_dry_run_reports_the_alias_and_replace_changes_and_writes_nothing(): void
    {
        $this->monorepo(['src/API' => ['api', self::ADDED]]);
        $this->alias('src/API', 'open-telemetry/api', '1.10.x-dev');
        $this->root(['open-telemetry/api' => '1.0.x-dev']);

        $this->assertSame(Command::SUCCESS, $this->prep(['--dry-run' => true]));
        $this->assertSame('1.10.x-dev', $this->branchAlias('src/API'));
        $this->assertSame(['open-telemetry/api' => '1.0.x-dev'], $this->replace());
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('open-telemetry/api: 1.10.x-dev -> 1.11.x-dev', $display);
        $this->assertStringContainsString('open-telemetry/api: 1.0.x-dev -> 1.11.x-dev', $display);
    }

    public function test_root_replace_follows_the_alias_of_every_split_package(): void
    {
        $this->monorepo(['src/API' => ['api', self::ADDED], 'src/SDK' => ['sdk', self::EMPTY]]);
        $this->alias('src/API', 'open-telemetry/api', '1.10.x-dev');
        $this->alias('src/SDK', 'open-telemetry/sdk', '1.16.x-dev');
        $this->root([
            'open-telemetry/api' => '1.0.x-dev',
            'open-telemetry/sdk' => '1.0.x-dev',
            'open-telemetry/sdk-contrib' => '1.0.x-dev',
        ]);

        $this->assertSame(Command::SUCCESS, $this->prep([], ['interactive' => false]));
        $this->assertSame([
            'open-telemetry/api' => '1.11.x-dev',
            'open-telemetry/sdk' => '1.16.x-dev',
            'open-telemetry/sdk-contrib' => '1.0.x-dev',
        ], $this->replace());
    }

    public function test_checkout_containing_the_branch_tip_passes(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);

        $this->assertSame(Command::SUCCESS, $this->prep([], ['interactive' => false]));
        $this->assertContains('https://api.github.com/repos/open-telemetry/opentelemetry-php/git/ref/heads/main', $this->requests);
        $this->assertSame([[$this->root->url(), 'abc123', 'HEAD']], $this->ancestorChecks);
        $this->assertStringContainsString('## 1.10.2 - 2026-11-03', $this->changelog('src/API'));
    }

    public function test_checkout_of_neither_monorepo_fails_naming_its_root(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);
        file_put_contents($this->root->url() . '/composer.json', '{"name":"acme/monorepo"}');

        $this->assertSame(Command::FAILURE, $this->prep([], ['interactive' => false]));
        $this->assertStringContainsString(
            'is not a checkout of open-telemetry/opentelemetry-php or open-telemetry/opentelemetry-php-contrib: its composer.json is named "acme/monorepo"',
            $this->tester->getDisplay()
        );
        $this->assertSame(self::FIXED, $this->changelog('src/API'));
    }

    public function test_checkout_without_the_branch_tip_fails_and_says_to_fetch(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);
        $this->tipIsLocal = false;

        $this->assertSame(Command::FAILURE, $this->prep([], ['inputs' => ['']]));
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('abc123', $display);
        $this->assertStringContainsString('open-telemetry/opentelemetry-php main', $display);
        $this->assertStringContainsStringIgnoringCase('fetch', $display);
        $this->assertStringNotContainsString('Version to release', $display);
        $this->assertSame(self::FIXED, $this->changelog('src/API'));
    }

    public function test_checkout_behind_the_branch_tip_fails(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);
        $this->tipIsAncestor = false;

        $this->assertSame(Command::FAILURE, $this->prep([], ['inputs' => ['']]));
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('abc123', $display);
        $this->assertStringContainsStringIgnoringCase('rebase', $display);
        $this->assertStringNotContainsString('Version to release', $display);
        $this->assertSame(self::FIXED, $this->changelog('src/API'));
    }

    public function test_uncommitted_changes_do_not_stop_a_prep(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);
        $this->changes = [' M src/API/composer.json'];

        $this->assertSame(Command::SUCCESS, $this->prep([], ['interactive' => false]));
        $this->assertStringContainsString('## 1.10.2 - 2026-11-03', $this->changelog('src/API'));
    }

    public function test_dry_run_warns_about_a_checkout_behind_the_branch_tip(): void
    {
        $this->monorepo(['src/API' => ['api', self::ADDED]]);
        $this->tipIsAncestor = false;

        $this->assertSame(Command::SUCCESS, $this->prep(['--dry-run' => true]));
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('[DRY-RUN]', $display);
        $this->assertStringContainsString('abc123', $display);
        $this->assertStringContainsString('1.11.0', $display);
        $this->assertSame(self::ADDED, $this->changelog('src/API'));
    }

    public function test_branch_option_picks_the_branch_looked_up(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);

        $this->assertSame(Command::SUCCESS, $this->prep(['--branch' => '1.x'], ['interactive' => false]));
        $this->assertContains('https://api.github.com/repos/open-telemetry/opentelemetry-php/git/ref/heads/1.x', $this->requests);
        $this->assertNotContains('https://api.github.com/repos/open-telemetry/opentelemetry-php/git/ref/heads/main', $this->requests);
    }

    public function test_contrib_checkout_is_checked_against_contrib(): void
    {
        $this->monorepo(['src/Instrumentation/Laravel' => ['contrib-auto-laravel', self::FIXED]]);
        file_put_contents($this->root->url() . '/composer.json', '{"name":"open-telemetry/opentelemetry-php-contrib"}');
        $this->releases = ['contrib-auto-laravel' => '1.0.0'];

        $this->assertSame(Command::SUCCESS, $this->prep([], ['interactive' => false]));
        $this->assertContains('https://api.github.com/repos/open-telemetry/opentelemetry-php-contrib/git/ref/heads/main', $this->requests);
    }

    public function test_checkout_of_an_unknown_monorepo_fails(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);
        file_put_contents($this->root->url() . '/composer.json', '{"name":"acme/monorepo"}');

        $this->assertSame(Command::FAILURE, $this->prep([], ['interactive' => false]));
        $this->assertStringContainsString('acme/monorepo', $this->tester->getDisplay());
        $this->assertSame(self::FIXED, $this->changelog('src/API'));
    }

    public function test_failed_branch_lookup_fails(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);
        $this->tip = null;

        $this->assertSame(Command::FAILURE, $this->prep([], ['interactive' => false]));
        $this->assertStringContainsString('main', $this->tester->getDisplay());
        $this->assertSame(self::FIXED, $this->changelog('src/API'));
    }

    public function test_checkout_git_cannot_read_fails(): void
    {
        $this->monorepo(['src/API' => ['api', self::FIXED]]);
        $this->gitError = new RuntimeException('fatal: not a git repository (or any of the parent directories): .git');

        $this->assertSame(Command::FAILURE, $this->prep([], ['interactive' => false]));
        $this->assertStringContainsString('fatal: not a git repository', $this->tester->getDisplay());
        $this->assertSame(self::FIXED, $this->changelog('src/API'));
    }

    /**
     * @param array<string, array{0: string, 1: ?string}> $packages prefix => [downstream project, changelog]
     */
    private function monorepo(array $packages): void
    {
        $splits = '';
        foreach ($packages as $prefix => [$project, $changelog]) {
            $splits .= "  - prefix: \"{$prefix}\"\n    target: \"https://\${GH_TOKEN}@github.com/opentelemetry-php/{$project}.git\"\n";
            if ($changelog !== null) {
                $dir = $this->root->url() . '/' . $prefix;
                is_dir($dir) || mkdir($dir, 0o777, true);
                file_put_contents("{$dir}/CHANGELOG.md", $changelog);
            }
        }
        file_put_contents($this->root->url() . '/.gitsplit.yml', "splits:\n{$splits}");
        file_put_contents($this->root->url() . '/composer.json', '{"name":"open-telemetry/opentelemetry"}');
    }

    /**
     * @param array<string, string> $require
     */
    private function composer(string $prefix, string $name, array $require): void
    {
        file_put_contents(
            $this->root->url() . "/{$prefix}/composer.json",
            json_encode(['name' => $name, 'require' => (object) $require])
        );
    }

    private function alias(string $prefix, string $name, string $alias): void
    {
        file_put_contents(
            $this->root->url() . "/{$prefix}/composer.json",
            json_encode(['name' => $name, 'extra' => ['branch-alias' => ['dev-main' => $alias]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param array<string, string> $replace
     */
    private function root(array $replace): void
    {
        file_put_contents(
            $this->root->url() . '/composer.json',
            json_encode(['name' => 'open-telemetry/opentelemetry', 'replace' => $replace], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function branchAlias(string $prefix): mixed
    {
        return json_decode((string) file_get_contents($this->root->url() . "/{$prefix}/composer.json"), true)['extra']['branch-alias']['dev-main'] ?? null;
    }

    private function replace(): mixed
    {
        return json_decode((string) file_get_contents($this->root->url() . '/composer.json'), true)['replace'] ?? null;
    }

    private function changelog(string $prefix): string
    {
        return (string) file_get_contents($this->root->url() . "/{$prefix}/CHANGELOG.md");
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options CommandTester options: interactive, inputs
     */
    private function prep(array $input, array $options = []): int
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request): Response {
            $url = (string) $request->getUri();
            $this->requests[] = $url;
            if (preg_match('#/git/ref/heads/(.+)$#', $url, $m) === 1) {
                return $this->tip === null
                    ? new Response(500)
                    : new Response(200, [], json_encode(['ref' => "refs/heads/{$m[1]}", 'object' => ['sha' => $this->tip]]));
            }
            if (preg_match('#/repos/opentelemetry-php/[^/]+$#', $url) === 1) {
                return new Response(200, [], json_encode(['full_name' => substr($url, strlen('https://api.github.com/repos/'))]));
            }
            if ($this->latestStatus !== 200) {
                return new Response($this->latestStatus);
            }
            preg_match('#/repos/opentelemetry-php/([^/]+)/releases/latest#', $url, $m);
            $tag = $this->releases[$m[1] ?? ''] ?? null;

            return $tag === null
                ? new Response(404)
                : new Response(200, [], json_encode(['tag_name' => $tag, 'created_at' => '2026-01-01T00:00:00Z']));
        });
        $reader = $this->createMock(PackageDependencyReader::class);
        $reader->method('read')->willReturnCallback(fn (): ProviderDependencies => $this->readError !== null
            ? throw $this->readError
            : $this->dependencies);
        $git = $this->createMock(GitRunner::class);
        $git->method('hasCommit')->willReturnCallback(fn (): bool => $this->gitError !== null
            ? throw $this->gitError
            : $this->tipIsLocal);
        $git->method('isAncestor')->willReturnCallback(function (string ...$args): bool {
            $this->ancestorChecks[] = $args;

            return $this->tipIsAncestor;
        });
        $git->method('changes')->willReturnCallback(fn (): array => $this->changes);
        $this->tester = new CommandTester(new ReleasePrepCommand(new GitHub($client), $reader, $git));
        $inputs = $options['inputs'] ?? [];
        unset($options['inputs']);
        $inputs !== [] && $this->tester->setInputs($inputs);

        return $this->tester->execute(
            $input + ['--path' => $this->root->url(), '--date' => self::DATE],
            $options
        );
    }
}
