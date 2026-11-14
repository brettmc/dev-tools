<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use OpenTelemetry\DevTools\Console\Release\CapturedOutput;
use OpenTelemetry\DevTools\Console\Release\GitHub;
use OpenTelemetry\DevTools\Console\Release\GitHubException;
use OpenTelemetry\DevTools\Console\Release\Overrides;
use OpenTelemetry\DevTools\Console\Release\ReleaseDescription;
use OpenTelemetry\DevTools\Package\Git\GitRunner;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Opens or rebuilds the release pull request of a monorepo, from `release:prep` run on a checkout
 * of main. The versions are those release:prep suggests, with the overrides in the pull request's
 * description on top.
 *
 * The checkout is changed and committed. The pull request's branch is replaced, never merged, so
 * the pull request never conflicts with main.
 */
class ReleasePrCommand extends AbstractReleaseCommand
{
    public const NAME = 'release:pr';
    public const BRANCH = 'otelbot/release';
    public const BOT = 'otelbot-php[bot]';
    public const BOT_EMAIL = '356036745+otelbot-php[bot]@users.noreply.github.com';

    private GitRunner $git;
    /** @var \Closure(): Command makes the release:prep command to run */
    private \Closure $prep;

    /**
     * @param (\Closure(): Command)|null $prep makes the release:prep command to run, a new one each
     *        time, as it keeps the state of a run
     */
    public function __construct(?GitHub $github = null, ?GitRunner $git = null, ?\Closure $prep = null)
    {
        parent::__construct($github);
        $this->git = $git ?? new GitRunner();
        $this->prep = $prep ?? fn (): Command => new ReleasePrepCommand($this->github, null, $this->git);
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Open or rebuild the release pull request of a monorepo, from release:prep on a checkout of main')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'path to a checkout of main, which is changed and committed (default: working directory)')
            ->addOption('repository', null, InputOption::VALUE_REQUIRED, 'the monorepo, ' . implode(' or ', self::AVAILABLE_REPOS))
            ->addOption('token', ['t'], InputOption::VALUE_REQUIRED, 'github token that can write the pull requests and contents of the monorepo (default: GITHUB_TOKEN)')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'release date, YYYY-MM-DD (default: today in UTC)')
            ->addOption('rebuild-only', null, InputOption::VALUE_NONE, 'rebuild the pull request if it is open and its overrides changed, as after an edit to its description')
            ->addOption('previous-body-file', null, InputOption::VALUE_REQUIRED, 'with --rebuild-only, a file holding the description before the edit')
            ->addOption('sender', null, InputOption::VALUE_REQUIRED, 'the user who asked for the pull request, who needs write access to the monorepo')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->registerInputAndOutput($input, $output);

        try {
            return $this->open($input);
        } catch (GitHubException|RuntimeException|\JsonException $e) {
            return $this->fail($e->getMessage());
        }
    }

    private function open(InputInterface $input): int
    {
        $repository = $input->getOption('repository');
        if (!in_array($repository, self::AVAILABLE_REPOS, true)) {
            return $this->fail('--repository must be ' . implode(' or ', self::AVAILABLE_REPOS));
        }
        $token = $this->token($input);
        if ($token === null) {
            return $this->fail(self::NAME . ' needs a GitHub token: --token, or GITHUB_TOKEN');
        }
        $date = $input->getOption('date') ?? gmdate('Y-m-d');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return $this->fail("--date must be YYYY-MM-DD, got \"{$date}\"");
        }
        $rebuildOnly = (bool) $input->getOption('rebuild-only');
        $before = null;
        if ($rebuildOnly) {
            $file = $input->getOption('previous-body-file');
            if ($file === null) {
                return $this->fail('--rebuild-only needs --previous-body-file, the description before the edit');
            }
            $before = @file_get_contents($file);
            if ($before === false) {
                return $this->fail("Cannot read {$file}");
            }
        }
        $path = $this->checkoutPath($input->getOption('path'));
        if ($path === null) {
            return Command::FAILURE;
        }

        $pulls = $this->github->openPullRequests($repository, self::BRANCH, self::BOT);
        if (count($pulls) > 1) {
            return $this->fail("{$repository} has more than one open release pull request");
        }
        $pull = $pulls[0] ?? null;
        if ($pull === null && $rebuildOnly) {
            $this->output->writeln("{$repository} has no open release pull request to rebuild");

            return Command::SUCCESS;
        }
        $previous = str_replace("\r", '', $pull['body'] ?? '');

        $overrides = Overrides::fromBody($previous);
        if ($before !== null && $overrides->equals(Overrides::fromBody($before))) {
            $this->output->writeln('The overrides are unchanged');

            return Command::SUCCESS;
        }
        $sender = $input->getOption('sender');
        if ($sender !== null) {
            $permission = $this->github->permission($repository, $sender);
            if ($permission !== 'admin' && $permission !== 'write') {
                return $this->fail("{$sender} has {$permission} access to {$repository}, not write");
            }
        }
        $invalid = $overrides->invalid();
        if ($invalid !== []) {
            return $this->fail('Each override must be <package>=<version|skip>. Not valid: ' . implode(', ', $invalid));
        }

        $arguments = ['--path' => $path, '--token' => $token, '--date' => $date, '--set' => $overrides->lines];
        //without --dry-run the markdown ends with instructions for a person at a terminal
        $buffer = new BufferedOutput();
        $status = $this->prep($arguments + ['--dry-run' => true, '--format' => 'markdown'], $buffer);
        $markdown = $buffer->fetch();
        if ($status !== Command::SUCCESS) {
            $this->output->write($markdown);

            return $this->fail('release:prep --dry-run failed');
        }
        $json = new CapturedOutput($this->output);
        if ($this->prep($arguments + ['--format' => 'json'], $json) !== Command::SUCCESS) {
            return $this->fail('release:prep failed');
        }
        $prep = json_decode($json->fetch(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($prep)) {
            return $this->fail('release:prep wrote no JSON object');
        }
        $sha = $this->git->head($path);

        if (($prep['release'] ?? false) !== true) {
            $this->output->writeln("Nothing to release from main at {$sha}");
            if ($pull !== null) {
                $this->github->closePullRequest($repository, $pull['number'], "Nothing to release from `main` at {$sha}.");
                $this->output->writeln("Closed #{$pull['number']}");
            }

            return Command::SUCCESS;
        }

        $title = "Release {$date}";
        $commit = $this->git->commitAll($path, self::message($title, $prep), self::BOT, self::BOT_EMAIL);
        $remote = "https://github.com/{$repository}.git";
        //the lease makes the push fail if another run moved the branch after this fetch
        $lease = $this->git->fetch($path, $remote, self::BRANCH);
        $push = $lease === null
            || $this->git->parent($path, $lease) !== $sha
            || !$this->git->sameTree($path, $lease, $commit);

        $description = (new ReleaseDescription($repository, $sha, $repository === self::AVAILABLE_REPOS['core']))
            ->render($markdown, $prep, $previous, $overrides);

        //the description is updated before the push, so the approval check on the new commit reads it
        if ($pull !== null) {
            $current = $this->github->pullRequest($repository, $pull['number']);
            //an edit since this run read the description has started a run of its own. The stale
            //note is not such an edit, and this rebuild replaces it.
            if (ReleaseDescription::unmarked($current['body']) !== ReleaseDescription::unmarked($previous)) {
                return $this->fail("The description of #{$pull['number']} changed during this run. The run for that edit rebuilds the pull request.");
            }
            if ($current['title'] !== $title || self::text($current['body']) !== self::text($description)) {
                $this->github->editPullRequest($repository, $pull['number'], $title, $description);
                $this->output->writeln("Updated the description of #{$pull['number']}");
            }
        }
        if ($push) {
            $this->git->push($path, $remote, self::BRANCH, $lease ?? '', $token);
            $this->output->writeln('Pushed ' . self::BRANCH . " at {$commit}");
        } else {
            $this->output->writeln(self::BRANCH . " already holds this release of {$sha}");
        }
        if ($pull === null) {
            $this->output->writeln('Opened ' . $this->github->createPullRequest($repository, $this->source_branch, self::BRANCH, $title, $description));
        }

        return Command::SUCCESS;
    }

    /**
     * Run release:prep in this process, without interaction.
     *
     * @param array<string, mixed> $arguments
     */
    private function prep(array $arguments, OutputInterface $output): int
    {
        $input = new ArrayInput($arguments);
        $input->setInteractive(false);

        try {
            return ($this->prep)()->run($input, $output);
        } finally {
            //release:prep traces the GitHub client on its own output
            $this->github->trace($this->output);
        }
    }

    /**
     * `Release <date>`, then each package being released, in the order to tag them.
     *
     * @param array<mixed> $prep
     */
    private static function message(string $title, array $prep): string
    {
        $versions = [];
        foreach (is_array($prep['packages'] ?? null) ? $prep['packages'] : [] as $package) {
            if (is_array($package) && is_string($package['package'] ?? null)) {
                $versions[$package['package']] = $package['version'] ?? null;
            }
        }
        $message = "{$title}\n\n";
        foreach (is_array($prep['order'] ?? null) ? $prep['order'] : [] as $package) {
            $message .= "{$package} {$versions[$package]}\n";
        }

        return $message;
    }

    /**
     * A description as compared for changes: GitHub may give it back with CRLF line ends, and
     * without the final newline.
     */
    private static function text(string $body): string
    {
        return rtrim(str_replace("\r", '', $body), "\n");
    }
}
