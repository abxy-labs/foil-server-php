<?php

declare(strict_types=1);

namespace Foil\Server\Api;

use Generator;
use Foil\Server\Http\HttpClient;
use Foil\Server\Resource\SessionDetail;
use Foil\Server\Resource\SessionSummary;
use Foil\Server\Result\ListResult;

final class SessionsApi
{
    public function __construct(private readonly HttpClient $http) {}

    public function list(
        ?int $limit = null,
        ?string $cursor = null,
        ?string $verdict = null,
        ?string $search = null,
    ): ListResult {
        $response = $this->http->requestJson('GET', '/v1/sessions', [
            'limit' => $limit,
            'cursor' => $cursor,
            'verdict' => $verdict,
            'search' => $search,
        ]);

        $items = [];
        foreach ((array) $response['data'] as $item) {
            $items[] = SessionSummary::fromArray((array) $item);
        }

        $pagination = (array) ($response['pagination'] ?? []);

        return new ListResult(
            $items,
            (int) $pagination['limit'],
            (bool) $pagination['has_more'],
            isset($pagination['next_cursor']) ? (string) $pagination['next_cursor'] : null,
        );
    }

    public function get(string $sessionId): SessionDetail
    {
        $response = $this->http->requestJson('GET', '/v1/sessions/' . rawurlencode($sessionId));
        return SessionDetail::fromArray((array) $response['data']);
    }

    public function attachClientUser(string $sessionId, string $clientUserId): SessionDetail
    {
        $response = $this->http->requestJson(
            'PATCH',
            '/v1/sessions/' . rawurlencode($sessionId),
            body: ['client_user_id' => $clientUserId],
        );
        return SessionDetail::fromArray((array) $response['data']);
    }

    public function clearClientUser(string $sessionId): SessionDetail
    {
        $response = $this->http->requestJson(
            'PATCH',
            '/v1/sessions/' . rawurlencode($sessionId),
            body: ['client_user_id' => null],
        );
        return SessionDetail::fromArray((array) $response['data']);
    }

    /**
     * @return Generator<int, SessionSummary>
     */
    public function iterate(
        ?int $limit = null,
        ?string $verdict = null,
        ?string $search = null,
    ): Generator {
        $cursor = null;

        do {
            $page = $this->list($limit, $cursor, $verdict, $search);
            foreach ($page->items as $item) {
                yield $item;
            }
            $cursor = $page->has_more ? $page->next_cursor : null;
        } while ($cursor !== null);
    }
}
