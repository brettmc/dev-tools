<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Integration\Console\Command\Changelog;

use OpenTelemetry\DevTools\Console\Command\Changelog\ChangelogCheckCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Changelog\ChangelogCheckCommand
 */
class ChangelogCheckCommandTest extends TestCase
{
    private const CHANGELOG = "# Changelog\n\n## Unreleased\n\n### Added\n\n- A new thing.\n";

    private string $dir;
    private string|false $githubActions;
    private CommandTester $tester;

    #[\Override]
    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/changelog-check-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->githubActions = getenv('GITHUB_ACTIONS');
        putenv('GITHUB_ACTIONS');
        $this->tester = new CommandTester(new ChangelogCheckCommand());

        $this->write('.gitsplit.yml', <<<'YAML'
            splits:
              - prefix: "src/API"
                target: "https://${GH_TOKEN}@github.com/opentelemetry-php/api.git"
              - prefix: "src/SDK"
                target: "https://${GH_TOKEN}@github.com/opentelemetry-php/sdk.git"
              - prefix: "src/Context"
                target: "https://${GH_TOKEN}@github.com/opentelemetry-php/context.git"
            YAML);
        $this->write('composer.json', '{"name":"open-telemetry/opentelemetry"}');
        foreach (['src/API', 'src/SDK', 'src/Context'] as $prefix) {
            $this->write("{$prefix}/composer.json", '{}');
            $this->write("{$prefix}/a.php", '<?php');
        }
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG);
        $this->write('src/SDK/CHANGELOG.md', self::CHANGELOG);
        $this->git('init', '--quiet');
        $this->commit('Initial');
    }

    #[\Override]
    public function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->mustRun();
        putenv($this->githubActions === false ? 'GITHUB_ACTIONS' : "GITHUB_ACTIONS={$this->githubActions}");
    }

    public function test_source_changed_without_its_changelog_fails(): void
    {
        $this->write('src/API/a.php', '<?php // changed');
        $this->commit('Change API');

        $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +changed, CHANGELOG.md not updated$/m', $this->display());
        $this->assertStringContainsString('Add an entry under Unreleased', $this->display());
        $this->assertStringContainsString('skip-changelog', $this->display());
        $this->assertStringNotContainsString('src/SDK', $this->display());
    }

    public function test_source_changed_with_its_changelog_passes(): void
    {
        $this->write('src/API/a.php', '<?php // changed');
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG . "- Another.\n");
        $this->commit('Change API');

        $this->assertSame(Command::SUCCESS, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +changed, CHANGELOG.md updated$/m', $this->display());
        $this->assertStringNotContainsString('Add an entry', $this->display());
    }

    public function test_changelog_changed_alone_passes(): void
    {
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG . "- Another.\n");
        $this->commit('Note API');

        $this->assertSame(Command::SUCCESS, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +CHANGELOG.md updated$/m', $this->display());
    }

    public function test_markdown_only_needs_no_entry(): void
    {
        $this->write('src/API/README.md', '# API');
        $this->commit('Document API');

        $this->assertSame(Command::SUCCESS, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +Markdown only, no entry needed$/m', $this->display());
    }

    public function test_split_without_a_changelog_is_reported_and_passes(): void
    {
        $this->write('src/Context/a.php', '<?php // changed');
        $this->commit('Change Context');

        $this->assertSame(Command::SUCCESS, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/Context +changed, has no CHANGELOG.md$/m', $this->display());
    }

    public function test_changes_outside_every_split_are_not_a_package(): void
    {
        $this->write('README.md', '# Monorepo');
        $this->write('src/APIx/a.php', '<?php');
        $this->commit('Root');

        $this->assertSame(Command::SUCCESS, $this->check('HEAD^1', 'HEAD'));
        $this->assertStringContainsString('Changes in git diff HEAD^1 HEAD:', $this->display());
        $this->assertStringContainsString('  No package changed.', $this->display());
    }

    public function test_changelog_that_does_not_parse_fails_naming_its_line(): void
    {
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG . "* Not a dash.\n");
        $this->commit('Note API');

        $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'));
        $this->assertStringContainsString('src/API/CHANGELOG.md: line 8: expected a "- " entry', $this->display());
        $this->assertStringNotContainsString('Add an entry under Unreleased', $this->display());
    }

    public function test_deleted_changelog_fails(): void
    {
        unlink("{$this->dir}/src/API/CHANGELOG.md");
        $this->commit('Drop API changelog');

        $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +CHANGELOG.md deleted$/m', $this->display());

        putenv('GITHUB_ACTIONS=true');
        $this->check('HEAD^1', 'HEAD');
        $this->assertStringContainsString("\n::error file=src/API/CHANGELOG.md::src/API/CHANGELOG.md was deleted\n", $this->display());
    }

    public function test_uncommitted_deleted_changelog_fails(): void
    {
        $this->git('update-ref', 'refs/remotes/origin/main', 'HEAD');
        unlink("{$this->dir}/src/API/CHANGELOG.md");

        $this->assertSame(Command::FAILURE, $this->check());
        $this->assertMatchesRegularExpression('/^  src\/API +CHANGELOG.md deleted$/m', $this->display());
    }

    public function test_a_range_is_checked_at_its_last_revision_not_the_working_tree(): void
    {
        $this->write('src/SDK/CHANGELOG.md', self::CHANGELOG . "* Not a dash.\n");
        $this->write('src/Context/CHANGELOG.md', self::CHANGELOG);
        $this->commit('Break SDK, add a Context changelog');
        $head = trim($this->git('rev-parse', 'HEAD'));
        $this->git('checkout', '--quiet', 'HEAD^1');

        foreach ([['HEAD', $head], ["HEAD..{$head}"], ["HEAD...{$head}"]] as $revisions) {
            $this->assertSame(Command::FAILURE, $this->check(...$revisions));
            $this->assertStringContainsString('src/SDK/CHANGELOG.md: line 8:', $this->display());
            $this->assertMatchesRegularExpression('/^  src\/Context +CHANGELOG.md updated$/m', $this->display());
        }
    }

    public function test_a_range_with_no_end_is_checked_at_head(): void
    {
        $this->write('src/SDK/CHANGELOG.md', self::CHANGELOG . "* Not a dash.\n");
        $this->commit('Break SDK');
        $this->write('src/SDK/CHANGELOG.md', self::CHANGELOG);

        $this->assertSame(Command::FAILURE, $this->check('HEAD^1..'));
        $this->assertStringContainsString('src/SDK/CHANGELOG.md: line 8:', $this->display());
    }

    public function test_one_revision_is_checked_against_the_working_tree(): void
    {
        $this->write('src/SDK/CHANGELOG.md', self::CHANGELOG . "* Not a dash.\n");
        $this->commit('Break SDK');
        $this->write('src/SDK/CHANGELOG.md', self::CHANGELOG . "- Fixed it.\n");

        $this->assertSame(Command::SUCCESS, $this->check('HEAD^1'));
    }

    public function test_more_than_two_revisions_fail(): void
    {
        $this->assertSame(Command::FAILURE, $this->check('HEAD', 'HEAD', 'HEAD'));
        $this->assertStringContainsString('at most two revisions', $this->display());
    }

    public function test_unchanged_changelog_is_not_parsed_for_a_range(): void
    {
        $this->write('src/SDK/CHANGELOG.md', self::CHANGELOG . "* Not a dash.\n");
        $this->commit('Break SDK');
        $this->write('src/API/README.md', '# API');
        $this->commit('Document API');

        $this->assertSame(Command::SUCCESS, $this->check('HEAD^1', 'HEAD'));
    }

    public function test_errors_are_annotated_under_github_actions(): void
    {
        $this->write('src/API/a.php', '<?php // changed');
        $this->write('src/SDK/CHANGELOG.md', "# Changelog\n");
        $this->commit('Change');

        $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'));
        $this->assertStringNotContainsString('::error', $this->display());

        putenv('GITHUB_ACTIONS=true');
        $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'));
        $this->assertStringContainsString(
            "\n::error file=src/API/CHANGELOG.md::src/API changed without an entry in its CHANGELOG.md\n",
            $this->display()
        );
        $this->assertStringContainsString("\n::error file=src/SDK/CHANGELOG.md::No \"## Unreleased\" section found\n", $this->display());
    }

    public function test_parse_error_annotation_names_the_line(): void
    {
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG . "* Not a dash.\n");
        $this->commit('Note API');
        putenv('GITHUB_ACTIONS=true');

        $this->check('HEAD^1', 'HEAD');

        $this->assertStringContainsString("\n::error file=src/API/CHANGELOG.md,line=8::line 8: expected", $this->display());
    }

    public function test_annotation_escapes_percent_signs(): void
    {
        $this->write('src/API/CHANGELOG.md', "# Changelog\n\n## Unreleased\n\n### 100% new\n");
        $this->commit('Note API');
        putenv('GITHUB_ACTIONS=true');

        $this->check('HEAD^1', 'HEAD');

        $this->assertStringContainsString('::line 5: Unknown section "100%25 new" under Unreleased', $this->display());
    }

    public function test_without_a_range_everything_since_origin_main_is_checked(): void
    {
        $this->git('update-ref', 'refs/remotes/origin/main', 'HEAD');
        $this->write('src/API/a.php', '<?php // committed');
        $this->commit('Change API');
        $this->write('src/SDK/a.php', '<?php // uncommitted');
        $this->write('src/Context/new.php', '<?php // untracked');

        $this->assertSame(Command::FAILURE, $this->check());
        $this->assertMatchesRegularExpression('/^Changes since [0-9a-f]{8} \(merge base with origin\/main\), including uncommitted files:$/m', $this->display());
        $this->assertMatchesRegularExpression('/^  src\/API +changed, CHANGELOG.md not updated$/m', $this->display());
        $this->assertMatchesRegularExpression('/^  src\/SDK +changed, CHANGELOG.md not updated$/m', $this->display());
        $this->assertMatchesRegularExpression('/^  src\/Context +changed, has no CHANGELOG.md$/m', $this->display());
    }

    public function test_without_a_range_every_changelog_is_parsed(): void
    {
        $this->git('update-ref', 'refs/remotes/origin/main', 'HEAD');
        $this->write('src/SDK/CHANGELOG.md', self::CHANGELOG . "* Not a dash.\n");
        $this->commit('Break SDK');
        $this->git('update-ref', 'refs/remotes/origin/main', 'HEAD');

        $this->assertSame(Command::FAILURE, $this->check());
        $this->assertStringContainsString('src/SDK/CHANGELOG.md: line 8:', $this->display());
    }

    public function test_revision_that_is_not_in_the_repository_fails(): void
    {
        $this->assertSame(Command::FAILURE, $this->check(str_repeat('a', 40), 'HEAD'));
        $this->assertStringContainsString(str_repeat('a', 40), $this->display());
    }

    public function test_path_that_is_not_a_monorepo_fails(): void
    {
        $this->assertSame(Command::FAILURE, $this->tester->execute(['--path' => "{$this->dir}/src"]));
        $this->assertStringContainsString('.gitsplit.yml', $this->tester->getDisplay());
    }

    public function test_split_without_a_target_fails_naming_it(): void
    {
        $this->write('.gitsplit.yml', "splits:\n  - prefix: \"src/API\"\n");

        $this->assertSame(Command::FAILURE, $this->check('HEAD'));
        $this->assertStringContainsString('split 1 has no target', $this->display());
    }

    public function test_path_that_does_not_exist_fails_naming_it(): void
    {
        $this->assertSame(Command::FAILURE, $this->tester->execute(['--path' => "{$this->dir}/missing"]));
        $this->assertStringContainsString("--path {$this->dir}/missing is not a directory", $this->tester->getDisplay());
    }

    private function check(string ...$revisions): int
    {
        return $this->tester->execute(['revisions' => $revisions, '--path' => $this->dir]);
    }

    private function display(): string
    {
        return $this->tester->getDisplay();
    }

    private function write(string $file, string $content): void
    {
        is_dir(dirname("{$this->dir}/{$file}")) || mkdir(dirname("{$this->dir}/{$file}"), 0o777, true);
        file_put_contents("{$this->dir}/{$file}", $content);
    }

    private function commit(string $message): void
    {
        $this->git('add', '--all');
        $this->git('-c', 'user.name=test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '-m', $message);
    }

    private function git(string ...$args): string
    {
        return (new Process(['git', ...$args], $this->dir))->mustRun()->getOutput();
    }
}
