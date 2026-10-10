<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Answers with queued responses (real Boxtal responses from tests/Fixtures) and keeps the requests.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $responses = [];

    public function queue(int $status, string $body, string $contentType = 'application/json'): self
    {
        $this->responses[] = new Response($status, ['Content-Type' => $contentType], $body);

        return $this;
    }

    public function queueFixture(string $fixture, int $status = 200): self
    {
        $type = str_ends_with($fixture, '.xml') ? 'application/xml' : 'application/json';

        return $this->queue($status, (string) file_get_contents(__DIR__.'/../Fixtures/'.$fixture), $type);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? throw new \LogicException('No response queued for '.$request->getMethod().' '.$request->getUri());
    }

    public function lastRequest(): RequestInterface
    {
        $last = array_key_last($this->requests);

        return null === $last ? throw new \LogicException('No request sent.') : $this->requests[$last];
    }
}
