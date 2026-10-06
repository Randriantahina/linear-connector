<?php

declare(strict_types=1);

namespace Uccello\LinearConnector\Connectors;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Thin, stateless GraphQL client for the Linear API. The caller passes the full
 * Authorization header value: a personal API key is sent as-is, an OAuth access
 * token is sent as "Bearer {token}". Credentials and refresh live in LinearConnector.
 */
final class LinearClient
{
    public const string ENDPOINT = 'https://api.linear.app/graphql';

    /** Attempts for a rate-limited or transient (5xx) response before giving up. */
    private const int MAX_ATTEMPTS = 3;

    /**
     * Run a GraphQL operation and return its `data` payload.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws LinearUnauthorizedException when the credentials are rejected
     * @throws \RuntimeException on any other API or GraphQL error
     */
    public function query(string $authorization, string $graphql, array $variables = []): array
    {
        $response = $this->send(fn (): Response => Http::withHeaders(['Authorization' => $authorization])
            ->acceptJson()
            ->asJson()
            ->post(self::ENDPOINT, ['query' => $graphql, 'variables' => (object) $variables]));

        if ($response->status() === 401 || $this->errorCode($response) === 'AUTHENTICATION_ERROR') {
            throw new LinearUnauthorizedException('Linear: credentials rejected.');
        }

        $errors = (array) $response->json('errors', []);

        if ($errors !== [] || $response->failed()) {
            $message = (string) data_get($errors, '0.message', 'HTTP '.$response->status());

            throw new \RuntimeException('Linear: API request failed ('.$message.').');
        }

        return (array) $response->json('data', []);
    }

    /**
     * Execute a request, retrying a 429, 5xx or GraphQL RATELIMITED response with a
     * backoff that honours Retry-After when present.
     *
     * @param  callable(): Response  $request
     */
    private function send(callable $request): Response
    {
        for ($attempt = 1; ; $attempt++) {
            $response = $request();

            $transient = $response->status() === 429
                || $response->serverError()
                || $this->errorCode($response) === 'RATELIMITED';

            if ($attempt >= self::MAX_ATTEMPTS || ! $transient) {
                return $response;
            }

            $retryAfter = (int) $response->header('Retry-After');
            $seconds = $retryAfter > 0 ? min($retryAfter, 30) : min(2 ** ($attempt - 1), 8);

            Sleep::for($seconds)->seconds();
        }
    }

    private function errorCode(Response $response): string
    {
        return (string) data_get($response->json(), 'errors.0.extensions.code', '');
    }
}
