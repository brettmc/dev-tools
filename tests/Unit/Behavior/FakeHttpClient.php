<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Behavior;

use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers with the given responses in order, and keeps the requests it got.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param list<ResponseInterface|\Throwable> $responses a throwable is thrown instead of answering
     */
    public function __construct(private array $responses)
    {
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->responses === []) {
            throw new LogicException('No response left for ' . $request->getMethod() . ' ' . $request->getUri());
        }

        $next = array_shift($this->responses);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
