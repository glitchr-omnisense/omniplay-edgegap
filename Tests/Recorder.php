<?php

namespace Omniplay\Edgegap\Tests;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Symfony's MockHttpClient answering by route and keeping each request as
 * it was sent: method, URL without its query, the query, the headers, the
 * JSON body decoded.
 */
final class Recorder
{
    /** @var list<array{method: string, url: string, query: array<mixed>, headers: array<string, string>, body: mixed}> */
    public array $requests = [];

    /** @var array<int, int> answers given by route */
    private array $given = [];

    /**
     * @param list<array{0: string, 1: int, 2: string|list<string>}> $routes a pattern matched against "METHOD url" (the query included), the status, the body - or the bodies, one per call, the last repeated
     */
    public function client(array $routes): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use ($routes): MockResponse {
            $parts = (array) parse_url($url);
            parse_str(\is_string($parts['query'] ?? null) ? $parts['query'] : '', $query);
            $headers = [];
            foreach (\is_array($options['normalized_headers'] ?? null) ? $options['normalized_headers'] : [] as $name => $lines) {
                if (\is_array($lines) && \is_string($lines[0] ?? null)) {
                    $headers[(string) $name] = substr($lines[0], \strlen((string) $name) + 2);
                }
            }
            $body = $options['body'] ?? '';
            $this->requests[] = [
                'method' => $method,
                'url' => explode('?', $url, 2)[0],
                'query' => $query,
                'headers' => $headers,
                'body' => \is_string($body) && '' !== $body ? json_decode($body, true) : null,
            ];
            foreach ($routes as $i => [$pattern, $status, $answer]) {
                if (1 === preg_match($pattern, $method.' '.$url)) {
                    $n = $this->given[$i] = ($this->given[$i] ?? -1) + 1;
                    $body = \is_array($answer) ? $answer[min($n, \count($answer) - 1)] : $answer;

                    return new MockResponse($body, ['http_code' => $status]);
                }
            }

            return new MockResponse('{"message": "not recorded"}', ['http_code' => 404]);
        });
    }

    /** @return list<string> "METHOD url" of each request, the query left out */
    public function calls(): array
    {
        return array_map(static fn (array $r) => $r['method'].' '.$r['url'], $this->requests);
    }
}
