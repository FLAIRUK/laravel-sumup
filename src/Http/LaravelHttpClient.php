<?php

namespace FLAIRUK\SumUp\Http;

use Illuminate\Http\Client\ConnectionException as LaravelConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use SumUp\Exception\ConnectionException;
use SumUp\HttpClient\HttpClientInterface;
use SumUp\HttpClient\RequestOptions;
use SumUp\HttpClient\Response;

/**
 * Sends the SumUp SDK's requests through Laravel's HTTP client, so that
 * Http::fake(), Http::preventStrayRequests() and the client's events work.
 */
class LaravelHttpClient implements HttpClientInterface
{
    /**
     * @param  array{0: int, 1: int}  $retry  GET retries on connection errors and 5xx: [times, sleep milliseconds]
     */
    public function __construct(
        protected Http $http,
        protected string $baseUrl,
        protected int $timeout = 30,
        protected int $connectTimeout = 10,
        protected array $retry = [2, 250],
    ) {}

    /**
     * @param  array<int|string, mixed>  $body
     * @param  array<string, string>  $headers
     *
     * @throws ConnectionException
     */
    public function send(string $method, string $url, array $body, array $headers, ?RequestOptions $options = null): Response
    {
        $method = strtoupper($method);

        try {
            $response = $this->prepare($method, $options)
                ->withHeaders($headers)
                ->send($method, ltrim($url, '/'), $body === [] ? [] : ['json' => $body]);
        } catch (LaravelConnectionException $e) {
            throw new ConnectionException($e->getMessage(), 0, null, $e);
        }

        $raw = $response->body();
        $decoded = json_decode($raw, true);

        return new Response(
            $response->status(),
            $decoded ?? ($raw === '' ? null : $raw),
            $response->headers(),
            $raw,
        );
    }

    /**
     * Only reads are retried: retrying a write after a timeout or 5xx could, for example, refund a payment twice.
     */
    protected function prepare(string $method, ?RequestOptions $options): PendingRequest
    {
        $request = $this->http->baseUrl($this->baseUrl)
            ->timeout($options?->timeout ?? $this->timeout)
            ->connectTimeout($options?->connectTimeout ?? $this->connectTimeout);

        [$times, $sleep] = $this->retry;

        if ($method !== 'GET' || $times < 1) {
            return $request;
        }

        return $request->retry($times, $sleep, fn (\Throwable $e) => $e instanceof LaravelConnectionException
            || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }
}
