<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Release;

use Nyholm\Psr7\Response;
use OpenTelemetry\DevTools\Console\Command\Release\PeclCommand;
use OpenTelemetry\DevTools\Console\Release\GitHub;
use OpenTelemetry\DevTools\Package\Git\GitRunner;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Release\PeclCommand
 */
class PeclCommandTest extends TestCase
{
    use UsesVfsTrait;

    private const PACKAGE_XML = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <package xmlns="http://pear.php.net/dtd/package-2.0" version="2.0">
          <name>opentelemetry</name>
          <date>2026-01-01</date>
          <time>00:00:00</time>
          <version><release>1.2.0</release><api>1.0</api></version>
          <stability><release>stable</release><api>stable</api></stability>
          <license>Apache 2.0</license>
          <notes>See https://github.com/open-telemetry/opentelemetry-php-instrumentation/releases/tag/1.2.0</notes>
          <changelog></changelog>
        </package>
        XML;

    /** @var list<string> */
    private array $urls = [];
    /** @var list<array{0: string, 1: string, 2: ?string}> sha, prefix and since of each log read */
    private array $logReads = [];
    /** @var list<array{sha: string, author: string, message: string}> */
    private array $log = [['sha' => 'abc', 'author' => 'Octo Cat', 'message' => 'Fix hooks (#7)']];
    private ?string $logError = null;
    private int $latestStatus = 200;
    private string $head = 'abc123';
    private string $mainTip = 'abc123';
    private bool $shallow = false;
    private ?string $headError = null;
    private CommandTester $tester;

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
        mkdir($this->root->url() . '/ext');
        file_put_contents($this->root->url() . '/ext/package.xml', self::PACKAGE_XML);

        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request): Response {
            $url = (string) $request->getUri();
            $this->urls[] = $url;
            $json = static fn (mixed $data): Response => new Response(200, [], json_encode($data));

            return match (true) {
                $this->latestStatus !== 200 && str_ends_with($url, '/releases/latest') => new Response($this->latestStatus, [], '{"message": "Server Error"}'),
                str_ends_with($url, '/releases/latest') => $json(['tag_name' => '1.2.0', 'created_at' => '2026-01-01T00:00:00Z']),
                str_ends_with($url, '/repos/open-telemetry/opentelemetry-php-instrumentation/git/ref/heads/main') => $json(['object' => ['sha' => $this->mainTip]]),
                default => new Response(404),
            };
        });

        $git = $this->createMock(GitRunner::class);
        $git->method('log')->willReturnCallback(function (string $path, string $sha, string $prefix, ?string $since): array {
            $this->logReads[] = [$sha, $prefix, $since];

            return $this->logError === null ? $this->log : throw new RuntimeException($this->logError);
        });
        $git->method('head')->willReturnCallback(fn (): string => $this->headError === null ? $this->head : throw new RuntimeException($this->headError));
        $git->method('isShallow')->willReturnCallback(fn (): bool => $this->shallow);

        $this->tester = new CommandTester(new PeclCommand(new GitHub($client), $git));
    }

    public function test_path_that_does_not_exist_fails_naming_it(): void
    {
        $this->assertSame(Command::FAILURE, $this->tester->execute(['--path' => '/nowhere/instrumentation']));
        $this->assertStringContainsString('--path /nowhere/instrumentation is not a directory', $this->tester->getDisplay());
    }

    public function test_package_xml_and_commits_are_read_from_the_checkout(): void
    {
        $this->tester->setInputs(['1.3.0', 'stable']);

        $status = $this->tester->execute(['--path' => $this->root->url()]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame([['HEAD', '.', '2026-01-01T00:00:01Z']], $this->logReads);
        $this->assertSame([], preg_grep('#raw\.githubusercontent\.com|/commits\?|/pulls#', $this->urls));
        $this->assertStringContainsString('<release>1.3.0</release>', $this->tester->getDisplay());
        $this->assertStringContainsString('releases/tag/1.3.0', $this->tester->getDisplay());
        $this->assertStringContainsString('<release>1.2.0</release>', $this->tester->getDisplay());
    }

    public function test_package_xml_moves_the_current_release_into_the_changelog(): void
    {
        $this->tester->setInputs(['1.3.0beta1', 'beta']);

        $this->tester->execute(['--path' => $this->root->url()]);

        $this->assertSame(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <package xmlns="http://pear.php.net/dtd/package-2.0" version="2.0">
              <name>opentelemetry</name>
              <date>DATE</date>
              <time>TIME</time>
              <version>
                <release>1.3.0beta1</release>
                <api>1.0</api>
              </version>
              <stability>
                <release>beta</release>
                <api>stable</api>
              </stability>
              <license>Apache 2.0</license>
              <notes>See https://github.com/open-telemetry/opentelemetry-php-instrumentation/releases/tag/1.3.0beta1</notes>
              <changelog>
                <release>
                  <date>2026-01-01</date>
                  <time>00:00:00</time>
                  <version>
                    <release>1.2.0</release>
                    <api>1.0</api>
                  </version>
                  <stability>
                    <release>stable</release>
                    <api>stable</api>
                  </stability>
                  <license>Apache 2.0</license>
                  <notes>See https://github.com/open-telemetry/opentelemetry-php-instrumentation/releases/tag/1.2.0</notes>
                </release>
              </changelog>
            </package>
            XML, $this->packageXml());
    }

    public function test_current_notes_are_moved_to_the_changelog_escaped(): void
    {
        file_put_contents($this->root->url() . '/ext/package.xml', str_replace(
            '<notes>See https://github.com/open-telemetry/opentelemetry-php-instrumentation/releases/tag/1.2.0</notes>',
            '<notes>Fix A &amp; B</notes>',
            self::PACKAGE_XML
        ));
        $this->tester->setInputs(['1.3.0', 'stable']);

        $this->tester->execute(['--path' => $this->root->url()]);

        $this->assertStringContainsString("<license>Apache 2.0</license>\n      <notes>Fix A &amp; B</notes>", $this->packageXml());
    }

    public function test_unreleased_commits_are_listed_by_subject_and_author(): void
    {
        $this->log = [['sha' => 'abc', 'author' => 'Octo Cat', 'message' => "Fix hooks (#7)\n\nWith a body."]];
        $this->tester->setInputs(['', '']);

        $this->tester->execute(['--path' => $this->root->url()]);

        $this->assertStringContainsString('1 unreleased change(s)', $this->tester->getDisplay());
        $this->assertStringContainsString('* Fix hooks (#7) (Octo Cat)', $this->tester->getDisplay());
        $this->assertStringNotContainsString('With a body.', $this->tester->getDisplay());
    }

    public function test_no_unreleased_commits_stops_without_force(): void
    {
        $this->log = [];

        $status = $this->tester->execute(['--path' => $this->root->url()]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('No unreleased commits since 1.2.0', $this->tester->getDisplay());
        $this->assertStringNotContainsString('<package', $this->tester->getDisplay());
    }

    public function test_path_without_package_xml_fails_before_calling_github(): void
    {
        unlink($this->root->url() . '/ext/package.xml');

        $status = $this->tester->execute(['--path' => $this->root->url()]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame([], $this->urls);
        $this->assertStringContainsString('ext/package.xml', $this->tester->getDisplay());
    }

    public function test_failed_latest_release_lookup_fails(): void
    {
        $this->latestStatus = 500;

        $status = $this->tester->execute(['--path' => $this->root->url()]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Server Error', $this->tester->getDisplay());
        $this->assertSame([], $this->logReads);
    }

    public function test_unreadable_commits_fail(): void
    {
        $this->logError = 'fatal: not a git repository';

        $status = $this->tester->execute(['--path' => $this->root->url()]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('fatal: not a git repository', $this->tester->getDisplay());
    }

    public function test_shallow_clone_fails_before_calling_github(): void
    {
        $this->shallow = true;

        $status = $this->tester->execute(['--path' => $this->root->url()]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('is a shallow clone', $this->tester->getDisplay());
        $this->assertSame([], $this->urls);
        $this->assertSame([], $this->logReads);
    }

    public function test_path_that_is_not_a_git_checkout_fails_before_calling_github(): void
    {
        $this->headError = 'fatal: not a git repository';

        $status = $this->tester->execute(['--path' => $this->root->url()]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('cannot be read by git: fatal: not a git repository', $this->tester->getDisplay());
        $this->assertSame([], $this->urls);
    }

    public function test_checkout_behind_main_fails(): void
    {
        $this->mainTip = 'def456';

        $status = $this->tester->execute(['--path' => $this->root->url()]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString(
            "{$this->root->url()} is at abc123, but open-telemetry/opentelemetry-php-instrumentation main is at def456. Check out and pull main before releasing.",
            $this->tester->getDisplay()
        );
        $this->assertSame([], $this->logReads);
    }

    private function packageXml(): string
    {
        $display = $this->tester->getDisplay();
        $xml = substr($display, (int) strpos($display, '<?xml'));

        return (string) preg_replace(
            '#<date>\d{4}-\d\d-\d\d</date>(\n  <time>)\d\d:\d\d:\d\d</time>(\n  <version>)#',
            '<date>DATE</date>${1}TIME</time>${2}',
            rtrim($xml),
            1
        );
    }
}
