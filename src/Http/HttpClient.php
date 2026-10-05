<?php

declare(strict_types=1);

namespace Foil\Server\Http;

use JsonException;
use Psr\Http\Client\ClientInterface as PsrHttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Foil\Server\Exception\FoilApiError;

final class HttpClient
{
    private const SDK_CLIENT_HEADER = 'abxy/foil-server';

    public function __construct(
        private readonly ?string $secretKey,
        private readonly string $baseUrl,
        private readonly PsrHttpClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?string $userAgent = null,
    ) {
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function requestJson(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        bool $expectContent = true,
    ): array {
        $request = $this->requestFactory->createRequest($method, $this->buildUrl($path, $query))
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-Foil-Client', self::SDK_CLIENT_HEADER);

        if ($this->secretKey === null || $this->secretKey === '') {
            throw new \Foil\Server\Exception\FoilConfigurationError(
                'Missing Foil secret key. Pass secretKey explicitly or set FOIL_SECRET_KEY.',
            );
        }
        $request = $request->withHeader('Authorization', 'Bearer ' . $this->secretKey);

        if ($this->userAgent !== null && $this->userAgent !== '') {
            $request = $request->withHeader('User-Agent', $this->userAgent);
        }

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        $response = $this->httpClient->sendRequest($request);
        $status = $response->getStatusCode();
        $requestId = $response->getHeaderLine('x-request-id');
        $payloadText = (string) $response->getBody();

        if ($status >= 400) {
            $payload = $this->decodeJsonSafely($payloadText);

            if (is_array($payload) && isset($payload['error']) && is_array($payload['error'])) {
                $error = $payload['error'];
                $details = isset($error['details']) && is_array($error['details']) ? $error['details'] : [];
                $fieldErrors = isset($details['fields']) && is_array($details['fields']) ? $details['fields'] : [];

                throw new FoilApiError(
                    $status,
                    (string) ($error['code'] ?? 'request.failed'),
                    (string) ($error['message'] ?? ($payloadText !== '' ? $payloadText : 'Foil request failed.')),
                    $requestId !== '' ? $requestId : (isset($error['request_id']) ? (string) $error['request_id'] : null),
                    $fieldErrors,
                    isset($error['docs_url']) ? (string) $error['docs_url'] : null,
                    $payload,
                );
            }

            throw new FoilApiError(
                $status,
                'request.failed',
                $payloadText !== '' ? $payloadText : 'Foil request failed.',
                $requestId !== '' ? $requestId : null,
                [],
                null,
                $payload,
            );
        }

        if (!$expectContent || $status === 204 || $payloadText === '') {
            return [];
        }

        try {
            $payload = json_decode($payloadText, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FoilApiError(
                $status,
                'response.invalid_json',
                'Foil API returned invalid JSON.',
                $requestId !== '' ? $requestId : null,
                [],
                null,
                $payloadText,
            );
        }

        if (!is_array($payload)) {
            throw new FoilApiError(
                $status,
                'response.invalid_json',
                'Foil API returned invalid JSON.',
                $requestId !== '' ? $requestId : null,
                [],
                null,
                $payload,
            );
        }

        return $payload;
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $compacted = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $compacted[$key] = $value;
        }

        if ($compacted === []) {
            return $url;
        }

        return $url . '?' . http_build_query($compacted, '', '&', PHP_QUERY_RFC3986);
    }

    private function decodeJsonSafely(string $payloadText): mixed
    {
        if ($payloadText === '') {
            return null;
        }

        try {
            return json_decode($payloadText, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $payloadText;
        }
    }
}
