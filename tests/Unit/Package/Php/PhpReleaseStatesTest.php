<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Php;

use Nyholm\Psr7\Response;
use OpenTelemetry\DevTools\Package\Php\PhpBranch;
use OpenTelemetry\DevTools\Package\Php\PhpReleaseStates;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\FakeHttpClient;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesPhpStatesTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * @covers \OpenTelemetry\DevTools\Package\Php\PhpReleaseStates
 * @covers \OpenTelemetry\DevTools\Package\Php\PhpBranch
 */
class PhpReleaseStatesTest extends TestCase
{
    use UsesPhpStatesTrait;

    public function test_it_lists_the_branches_with_dates_oldest_first(): void
    {
        $branches = PhpReleaseStates::parse($this->phpStatesJson());

        $this->assertSame(['8.0', '8.1', '8.2', '8.3', '8.4', '8.5', '8.6'], array_map(static fn (PhpBranch $b): string => $b->version, $branches));
        $this->assertSame('2022-12-08', $branches[2]->released->format('Y-m-d'));
        $this->assertSame('2026-12-31', $branches[2]->securitySupportEnd->format('Y-m-d'));
    }

    public function test_it_sorts_versions_numerically(): void
    {
        $json = (string) json_encode(['8' => [
            '8.10' => ['initial_release' => '2030-11-01T00:00:00+00:00', 'security_support_end' => '2034-12-31T00:00:00+00:00'],
            '8.9' => ['initial_release' => '2029-11-01T00:00:00+00:00', 'security_support_end' => '2033-12-31T00:00:00+00:00'],
        ]]);

        $this->assertSame(['8.9', '8.10'], array_map(static fn (PhpBranch $b): string => $b->version, PhpReleaseStates::parse($json)));
    }

    /**
     * @dataProvider invalidStates
     */
    public function test_it_rejects_what_it_cannot_read(string $json, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        PhpReleaseStates::parse($json);
    }

    public static function invalidStates(): iterable
    {
        yield 'not json' => ['<html>', 'not valid JSON'];
        yield 'no branches' => ['{"3":{"3.0":{"initial_release":null,"security_support_end":null}}}', 'no branch with readable dates'];
        yield 'only a bad date' => ['{"8":{"8.2":{"initial_release":"soon","security_support_end":"2026-12-31T00:00:00+00:00"}}}', 'no branch with readable dates'];
    }

    public function test_a_branch_with_an_unreadable_date_does_not_stop_the_others(): void
    {
        $json = (string) json_encode(['8' => [
            '8.1' => ['initial_release' => 'TBD', 'security_support_end' => '2025-12-31T00:00:00+00:00'],
            '8.2' => ['initial_release' => '2022-12-08T00:00:00+00:00', 'security_support_end' => ['2026-12-31']],
            '8.3' => ['initial_release' => '2023-11-23T00:00:00+00:00', 'security_support_end' => '2027-12-31T00:00:00+00:00'],
        ]]);

        $this->assertSame(['8.3'], array_map(static fn (PhpBranch $b): string => $b->version, PhpReleaseStates::parse($json)));
    }

    public function test_a_date_that_does_not_exist_is_unreadable_rather_than_rolled_over(): void
    {
        $json = (string) json_encode(['8' => [
            '8.2' => ['initial_release' => '2022-12-08T00:00:00+00:00', 'security_support_end' => '2026-02-31T00:00:00+00:00'],
            '8.3' => ['initial_release' => '2023-11-23T00:00:00+00:00', 'security_support_end' => '2027-12-31T00:00:00+00:00'],
        ]]);

        $this->assertSame(['8.3'], array_map(static fn (PhpBranch $b): string => $b->version, PhpReleaseStates::parse($json)));
    }

    public function test_it_fetches_and_parses_the_states(): void
    {
        $client = new FakeHttpClient([new Response(200, [], $this->phpStatesJson())]);

        $branches = (new PhpReleaseStates($client))->fetch();

        $this->assertCount(7, $branches);
        $this->assertSame('GET', $client->requests[0]->getMethod());
        $this->assertSame(PhpReleaseStates::URL, (string) $client->requests[0]->getUri());
    }

    public function test_it_fails_cleanly_when_php_net_cannot_be_reached(): void
    {
        $down = new class('connection refused') extends \RuntimeException implements ClientExceptionInterface {
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not read https://www.php.net/releases/states.php: connection refused');

        (new PhpReleaseStates(new FakeHttpClient([$down])))->fetch();
    }

    public function test_it_fails_when_php_net_does_not_answer_ok(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');

        (new PhpReleaseStates(new FakeHttpClient([new Response(503)])))->fetch();
    }
}
