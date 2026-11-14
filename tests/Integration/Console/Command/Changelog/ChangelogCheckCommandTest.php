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
    private const DEFINITION_12 = "[#12]: https://github.com/open-telemetry/opentelemetry-php/pull/12\n";
    private const RELEASED = "# Changelog\n\n## Unreleased\n\n### Added\n\n- A new thing.\n\n## 1.0.0 - 2026-01-31\n\n### Fixed\n\n- An old fix.\n";

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
            project_url: "https://github.com/open-telemetry/opentelemetry-php.git"
            splits:
              - prefix: "src/API"
                target: "https://${GH_TOKEN}@github.com/opentelemetry-php/api.git"
              - prefix: "src/SDK"
                target: "https://${GH_TOKEN}@github.com/opentelemetry-php/sdk.git"
              - prefix: "src/Context"
                target: "https://${GH_TOKEN}@github.com/opentelemetry-php/context.git"
            YAML);
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

    public function test_file_moved_between_packages_changes_both(): void
    {
        $this->git('mv', 'src/API/a.php', 'src/SDK/b.php');
        $this->commit('Move a file to SDK');

        $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +changed, CHANGELOG.md not updated$/m', $this->display());
        $this->assertMatchesRegularExpression('/^  src\/SDK +changed, CHANGELOG.md not updated$/m', $this->display());
    }

    public function test_entry_under_a_released_section_fails(): void
    {
        $this->write('src/API/CHANGELOG.md', self::RELEASED);
        $this->commit('Release API');
        $this->write('src/API/a.php', '<?php // changed');
        $this->write('src/API/CHANGELOG.md', self::RELEASED . "- Landed in the wrong section.\n");
        $this->commit('Change API');

        $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +changed, Unreleased not updated$/m', $this->display());
        $this->assertStringContainsString('Add an entry under Unreleased', $this->display());

        putenv('GITHUB_ACTIONS=true');
        $this->check('HEAD^1', 'HEAD');
        $this->assertStringContainsString(
            "\n::error file=src/API/CHANGELOG.md::src/API changed without an entry under Unreleased in its CHANGELOG.md\n",
            $this->display()
        );
    }

    public function test_rewrapped_entry_is_not_an_unreleased_change(): void
    {
        $this->write('src/API/a.php', '<?php // changed');
        $this->write('src/API/CHANGELOG.md', "# Changelog\n\n## Unreleased\n\n### Added\n\n- A new\n  thing.\n");
        $this->commit('Change API');

        $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +changed, Unreleased not updated$/m', $this->display());
    }

    public function test_removed_entry_is_an_unreleased_change(): void
    {
        $this->write('src/API/a.php', '<?php // reverted');
        $this->write('src/API/CHANGELOG.md', "# Changelog\n\n## Unreleased\n");
        $this->commit('Revert API');

        $this->assertSame(Command::SUCCESS, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +changed, CHANGELOG.md updated$/m', $this->display());
    }

    public function test_base_changelog_that_does_not_parse_has_no_unreleased_entries(): void
    {
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG . "* Not a dash.\n");
        $this->commit('Break API');
        $this->write('src/API/a.php', '<?php // changed');
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG . "- A dash.\n");
        $this->commit('Change API');

        $this->assertSame(Command::SUCCESS, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +changed, CHANGELOG.md updated$/m', $this->display());
    }

    public function test_emptying_unreleased_of_a_base_that_does_not_parse_is_not_an_entry(): void
    {
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG . "* Not a dash.\n");
        $this->commit('Break API');
        $this->write('src/API/a.php', '<?php // changed');
        $this->write('src/API/CHANGELOG.md', "# Changelog\n\n## Unreleased\n");
        $this->commit('Change API');

        $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +changed, Unreleased not updated$/m', $this->display());
    }

    public function test_one_revision_compares_unreleased_with_the_working_tree(): void
    {
        $this->write('src/API/CHANGELOG.md', self::RELEASED);
        $this->commit('Release API');
        $this->write('src/API/a.php', '<?php // changed');
        $this->write('src/API/CHANGELOG.md', self::RELEASED . "- Landed in the wrong section.\n");

        $this->assertSame(Command::FAILURE, $this->check('HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +changed, Unreleased not updated$/m', $this->display());
    }

    public function test_without_a_range_unreleased_is_compared_with_the_merge_base(): void
    {
        $this->write('src/API/CHANGELOG.md', self::RELEASED);
        $this->commit('Release API');
        $this->git('update-ref', 'refs/remotes/origin/main', 'HEAD');
        $this->write('src/API/a.php', '<?php // changed');
        $this->write('src/API/CHANGELOG.md', self::RELEASED . "- Landed in the wrong section.\n");

        $this->assertSame(Command::FAILURE, $this->check());
        $this->assertMatchesRegularExpression('/^  src\/API +changed, Unreleased not updated$/m', $this->display());
    }

    public function test_three_dot_range_compares_unreleased_with_the_merge_base(): void
    {
        $this->write('src/API/CHANGELOG.md', self::RELEASED);
        $this->commit('Release API');
        $this->git('branch', '-M', 'main');
        $this->git('branch', 'feature');
        $this->write('src/API/CHANGELOG.md', str_replace('- A new thing.', "- A new thing.\n- Merged to main meanwhile.", self::RELEASED));
        $this->commit('Note API on main');
        $this->git('checkout', '--quiet', 'feature');
        $this->write('src/API/a.php', '<?php // changed');
        $this->write('src/API/CHANGELOG.md', self::RELEASED . "- Landed in the wrong section.\n");
        $this->commit('Change API');

        $this->assertSame(Command::FAILURE, $this->check('main...feature'));
        $this->assertMatchesRegularExpression('/^  src\/API +changed, Unreleased not updated$/m', $this->display());
    }

    public function test_changed_released_section_fails_naming_it(): void
    {
        $this->write('src/API/CHANGELOG.md', self::RELEASED);
        $this->commit('Release API');
        $this->write('src/API/CHANGELOG.md', str_replace('An old fix.', 'An old fix, reworded.', self::RELEASED));
        $this->commit('Reword API');

        $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'));
        $this->assertStringContainsString('src/API/CHANGELOG.md: released section 1.0.0 changed', $this->display());
        $this->assertStringContainsString('skip-changelog', $this->display());

        putenv('GITHUB_ACTIONS=true');
        $this->check('HEAD^1', 'HEAD');
        $this->assertStringContainsString("\n::error file=src/API/CHANGELOG.md::released section 1.0.0 changed\n", $this->display());
    }

    public function test_entry_under_a_released_section_names_the_section(): void
    {
        $this->write('src/API/CHANGELOG.md', self::RELEASED);
        $this->commit('Release API');
        $this->write('src/API/a.php', '<?php // changed');
        $this->write('src/API/CHANGELOG.md', self::RELEASED . "- Landed in the wrong section.\n");
        $this->commit('Change API');

        $this->check('HEAD^1', 'HEAD');

        $this->assertStringContainsString('src/API/CHANGELOG.md: released section 1.0.0 changed', $this->display());
    }

    public function test_changed_released_heading_fails(): void
    {
        $this->write('src/API/CHANGELOG.md', self::RELEASED);
        $this->commit('Release API');
        $this->write('src/API/CHANGELOG.md', str_replace('## 1.0.0 - 2026-01-31', '## 1.0.0 - 2026-02-01', self::RELEASED));
        $this->commit('Redate API');

        $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'));
        $this->assertStringContainsString('src/API/CHANGELOG.md: released section 1.0.0 changed', $this->display());
    }

    public function test_removed_released_section_fails(): void
    {
        $this->write('src/API/CHANGELOG.md', self::RELEASED);
        $this->commit('Release API');
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG);
        $this->commit('Drop API history');

        $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'));
        $this->assertStringContainsString('src/API/CHANGELOG.md: released section 1.0.0 removed', $this->display());
    }

    public function test_new_released_section_passes(): void
    {
        $this->write('src/API/CHANGELOG.md', self::RELEASED);
        $this->commit('Release API');
        $this->write('src/API/a.php', '<?php // alias moved');
        $this->write('src/API/CHANGELOG.md', str_replace("## Unreleased\n", "## Unreleased\n\n## 1.1.0 - 2026-02-28\n", self::RELEASED));
        $this->commit('Prepare release');

        $this->assertSame(Command::SUCCESS, $this->check('HEAD^1', 'HEAD'));
        $this->assertStringNotContainsString('released section', $this->display());
    }

    public function test_new_link_definition_does_not_change_released_sections(): void
    {
        $this->write('src/API/CHANGELOG.md', self::RELEASED);
        $this->commit('Release API');
        $this->write('src/API/CHANGELOG.md', str_replace('- A new thing.', '- A new thing ([#7]).', self::RELEASED) . "\n[#7]: https://x/7\n");
        $this->commit('Link API');

        $this->assertSame(Command::SUCCESS, $this->check('HEAD^1', 'HEAD'));
    }

    public function test_new_entry_linking_the_pull_request_passes(): void
    {
        $this->write('src/API/a.php', '<?php // changed');
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG . "- Another ([#12]).\n\n" . self::DEFINITION_12);
        $this->commit('Change API');

        $this->assertSame(Command::SUCCESS, $this->checkPr('12', 'HEAD^1', 'HEAD'));
    }

    public function test_new_entry_linking_the_pull_request_inline_passes(): void
    {
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG . "- Another ([#12](https://github.com/open-telemetry/opentelemetry-php/pull/12)).\n");
        $this->commit('Note API');

        $this->assertSame(Command::SUCCESS, $this->checkPr('12', 'HEAD^1', 'HEAD'));
    }

    public function test_new_entry_without_the_pull_request_link_fails_with_the_text_to_add(): void
    {
        $this->write('src/API/a.php', '<?php // changed');
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG . "- Another.\n");
        $this->commit('Change API');

        $this->assertSame(Command::FAILURE, $this->checkPr('12', 'HEAD^1', 'HEAD'));
        $this->assertStringContainsString('src/API/CHANGELOG.md: entry doesn\'t link to #12: Another.', $this->display());
        $this->assertStringContainsString("End each new entry with ([#12]), and add this line at the end of the file:\n" . self::DEFINITION_12, $this->display());

        putenv('GITHUB_ACTIONS=true');
        $this->checkPr('12', 'HEAD^1', 'HEAD');
        $this->assertStringContainsString("\n::error file=src/API/CHANGELOG.md::entry doesn't link to #12: Another.\n", $this->display());
    }

    public function test_new_entry_linking_another_pull_request_fails(): void
    {
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG . "- Another ([#12]).\n\n" . self::DEFINITION_12);
        $this->commit('Note API');

        $this->assertSame(Command::FAILURE, $this->checkPr('13', 'HEAD^1', 'HEAD'));
        $this->assertStringContainsString('entry doesn\'t link to #13: Another ([#12]).', $this->display());
    }

    public function test_reworded_entry_must_link_the_pull_request(): void
    {
        $this->write('src/API/CHANGELOG.md', str_replace('A new thing.', 'A newer thing.', self::CHANGELOG));
        $this->commit('Reword API');

        $this->assertSame(Command::FAILURE, $this->checkPr('12', 'HEAD^1', 'HEAD'));
        $this->assertStringContainsString('entry doesn\'t link to #12: A newer thing.', $this->display());
    }

    public function test_new_copy_of_an_existing_entry_must_link_the_pull_request(): void
    {
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG . "\n### Fixed\n\n- A new thing.\n");
        $this->commit('Note API');

        $this->assertSame(Command::FAILURE, $this->checkPr('12', 'HEAD^1', 'HEAD'));
        $this->assertSame(1, substr_count($this->display(), "entry doesn't link to #12: A new thing."));
    }

    public function test_entries_already_unreleased_need_no_link(): void
    {
        $this->write('src/API/CHANGELOG.md', str_replace('- A new thing.', "- A first ([#12]).\n- A new thing.", self::CHANGELOG) . "\n" . self::DEFINITION_12);
        $this->commit('Note API');

        $this->assertSame(Command::SUCCESS, $this->checkPr('12', 'HEAD^1', 'HEAD'));
    }

    public function test_release_that_empties_unreleased_passes_with_a_pull_request(): void
    {
        $this->write('src/API/a.php', '<?php // alias moved');
        $this->write('src/API/CHANGELOG.md', str_replace("## Unreleased\n", "## Unreleased\n\n## 1.1.0 - 2026-02-28\n", self::CHANGELOG));
        $this->commit('Prepare release');

        $this->assertSame(Command::SUCCESS, $this->checkPr('12', 'HEAD^1', 'HEAD'));
    }

    public function test_every_entry_of_a_base_that_does_not_parse_must_link(): void
    {
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG . "* Not a dash.\n");
        $this->commit('Break API');
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG . "- A dash ([#12]).\n\n" . self::DEFINITION_12);
        $this->commit('Fix API');

        $this->assertSame(Command::FAILURE, $this->checkPr('12', 'HEAD^1', 'HEAD'));
        $this->assertStringContainsString('entry doesn\'t link to #12: A new thing.', $this->display());
        $this->assertStringNotContainsString('A dash', $this->display());
    }

    public function test_without_a_range_only_changed_changelogs_need_the_link(): void
    {
        $this->git('update-ref', 'refs/remotes/origin/main', 'HEAD');
        $this->write('src/API/CHANGELOG.md', self::CHANGELOG . "- Another ([#12]).\n\n" . self::DEFINITION_12);

        $this->assertSame(Command::SUCCESS, $this->checkPr('12'));
        $this->assertStringNotContainsString('src/SDK', $this->display());
    }

    public function test_pull_request_must_be_a_number(): void
    {
        $this->assertSame(Command::FAILURE, $this->checkPr('abc', 'HEAD'));
        $this->assertStringContainsString('--pr must be a pull request number, not "abc"', $this->display());
    }

    public function test_pull_request_needs_a_project_url(): void
    {
        $this->write('.gitsplit.yml', (string) preg_replace('/^project_url:.*\n/', '', (string) file_get_contents("{$this->dir}/.gitsplit.yml")));

        $this->assertSame(Command::FAILURE, $this->checkPr('12', 'HEAD'));
        $this->assertStringContainsString('--pr needs project_url in .gitsplit.yml, to know the repository', $this->display());
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
        $this->assertMatchesRegularExpression('/^  src\/API +no source changed, no entry needed$/m', $this->display());
    }

    public function test_change_to_other_files_needs_no_entry(): void
    {
        $this->write('src/API/phpunit.xml.dist', '<phpunit/>');
        $this->write('src/API/.gitattributes', '/tests export-ignore');
        $this->commit('Configure API tests');

        $this->assertSame(Command::SUCCESS, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +no source changed, no entry needed$/m', $this->display());
    }

    public function test_change_to_tests_and_examples_needs_no_entry(): void
    {
        $this->write('src/API/tests/Unit/ATest.php', '<?php');
        $this->write('src/API/examples/a.php', '<?php');
        $this->write('src/API/examples/app/composer.json', '{}');
        $this->write('src/API/example/a.php', '<?php');
        $this->commit('Test API');

        $this->assertSame(Command::SUCCESS, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +no source changed, no entry needed$/m', $this->display());
    }

    public function test_change_to_tool_configs_needs_no_entry(): void
    {
        $this->write('src/API/.phan/config.php', '<?php');
        $this->write('src/API/.php-cs-fixer.php', '<?php');
        $this->write('src/API/ignore-by-php-version.neon.php', '<?php');
        $this->commit('Configure API tools');

        $this->assertSame(Command::SUCCESS, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +no source changed, no entry needed$/m', $this->display());
    }

    public function test_php_named_like_a_tool_config_below_the_package_root_needs_an_entry(): void
    {
        foreach (['src/API/src/.phan/config.php', 'src/API/src/.php-cs-fixer.php', 'src/API/src/a.neon.php'] as $file) {
            $this->write($file, '<?php');
            $this->commit("Add {$file}");

            $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'), $file);
            $this->assertMatchesRegularExpression('/^  src\/API +changed, CHANGELOG.md not updated$/m', $this->display());
        }
    }

    public function test_php_under_a_source_directory_named_tests_needs_an_entry(): void
    {
        $this->write('src/API/src/tests/a.php', '<?php');
        $this->commit('Change API');

        $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +changed, CHANGELOG.md not updated$/m', $this->display());
    }

    public function test_composer_json_change_needs_an_entry(): void
    {
        $this->write('src/API/composer.json', '{"require": {}}');
        $this->commit('Change API dependencies');

        $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/API +changed, CHANGELOG.md not updated$/m', $this->display());
    }

    public function test_split_without_a_changelog_fails(): void
    {
        $this->write('src/Context/a.php', '<?php // changed');
        $this->commit('Change Context');

        $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'));
        $this->assertMatchesRegularExpression('/^  src\/Context +changed, has no CHANGELOG.md$/m', $this->display());
        $this->assertStringContainsString('Add a CHANGELOG.md to each package without one', $this->display());

        putenv('GITHUB_ACTIONS=true');
        $this->check('HEAD^1', 'HEAD');
        $this->assertStringContainsString("\n::error file=src/Context/CHANGELOG.md::src/Context has no CHANGELOG.md\n", $this->display());
    }

    public function test_markdown_only_change_to_a_split_without_a_changelog_fails(): void
    {
        $this->write('src/Context/README.md', '# Context');
        $this->commit('Document Context');

        $this->assertSame(Command::FAILURE, $this->check('HEAD^1', 'HEAD'));
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

    private function checkPr(string $pr, string ...$revisions): int
    {
        return $this->tester->execute(['revisions' => $revisions, '--path' => $this->dir, '--pr' => $pr]);
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
