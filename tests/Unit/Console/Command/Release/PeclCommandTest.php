<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Release;

use Nyholm\Psr7\Response;
use OpenTelemetry\DevTools\Console\Command\Release\PeclCommand;
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
                str_ends_with($url, '/releases/latest') => $json(['tag_name' => '1.2.0', 'created_at' => '2026-01-01T00:00:00Z']),
                default => new Response(404),
            };
        });

        $git = $this->createMock(GitRunner::class);
        $git->method('log')->willReturnCallback(function (string $path, string $sha, string $prefix, ?string $since): array {
            $this->logReads[] = [$sha, $prefix, $since];

            return $this->logError === null ? $this->log : throw new RuntimeException($this->logError);
        });

        $this->tester = new CommandTester(new PeclCommand($client, $git));
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

    public function test_unreadable_commits_fail(): void
    {
        $this->logError = 'fatal: not a git repository';

        $status = $this->tester->execute(['--path' => $this->root->url()]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('fatal: not a git repository', $this->tester->getDisplay());
    }
}
