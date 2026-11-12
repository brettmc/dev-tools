<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Php;

use DateTimeImmutable;
use DateTimeZone;
use Http\Discovery\Psr18ClientDiscovery;
use Nyholm\Psr7\Request;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/**
 * The support state of every PHP branch, from https://www.php.net/releases/states.php.
 *
 * That page lists released branches only, grouped by major version:
 * `{"8": {"8.5": {"initial_release": "2025-11-20T00:00:00+00:00", "security_support_end": "...", ...}}}`
 */
class PhpReleaseStates
{
    public const URL = 'https://www.php.net/releases/states.php';

    /**
     * @param ClientInterface|null $client a PSR-18 client, discovered when it is first needed
     */
    public function __construct(private readonly ?ClientInterface $client = null)
    {
    }

    /**
     * @return list<PhpBranch> oldest first
     */
    public function fetch(): array
    {
        $client = $this->client ?? Psr18ClientDiscovery::find();

        try {
            $response = $client->sendRequest(new Request('GET', self::URL, [
                'Accept' => 'application/json',
                'User-Agent' => 'php-' . PHP_VERSION,
            ]));
        } catch (ClientExceptionInterface $e) {
            throw new RuntimeException(sprintf('Could not read %s: %s', self::URL, $e->getMessage()), 0, $e);
        }
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(sprintf('Could not read %s: HTTP %d', self::URL, $response->getStatusCode()));
        }

        return self::parse((string) $response->getBody());
    }

    /**
     * @return list<PhpBranch> oldest first
     */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('The PHP release states are not valid JSON');
        }
        $branches = [];
        foreach ($data as $major) {
            foreach (is_array($major) ? $major : [] as $version => $state) {
                $version = (string) $version;
                $released = self::date($state, 'initial_release');
                $securityEnd = self::date($state, 'security_support_end');
                //a branch without readable dates, as the oldest ones, is of no use and not worth failing for
                if ($released !== null && $securityEnd !== null) {
                    $branches[] = new PhpBranch($version, $released, $securityEnd);
                }
            }
        }
        if ($branches === []) {
            throw new RuntimeException('The PHP release states list no branch with readable dates');
        }
        usort($branches, static fn (PhpBranch $a, PhpBranch $b): int => version_compare($a->version, $b->version));

        return $branches;
    }

    private static function date(mixed $state, string $key): ?DateTimeImmutable
    {
        $value = is_array($state) ? ($state[$key] ?? null) : null;
        $day = is_string($value) ? substr($value, 0, 10) : '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));

        //2026-02-31 would roll over to March
        return $date !== false && $date->format('Y-m-d') === $day ? $date : null;
    }
}
