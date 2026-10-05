<?php

declare(strict_types=1);

namespace Foil\Server\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Foil\Server\Client;
use Foil\Server\Exception\FoilApiError;
use Foil\Server\Exception\FoilConfigurationError;
use Foil\Server\Tests\Support\FixtureLoader;
use Foil\Server\Tests\Support\JsonResponse;
use Foil\Server\Tests\Support\TestHttpClient;

final class ClientTest extends TestCase
{
    public function testUsesEnvSecretKeyByDefault(): void
    {
        $original = getenv('FOIL_SECRET_KEY');
        putenv('FOIL_SECRET_KEY=sk_env_default');

        $fixture = FixtureLoader::load('api/sessions/list.json');
        $factory = new Psr17Factory();
        $httpClient = new TestHttpClient(
            static fn (RequestInterface $request) => JsonResponse::create($fixture),
        );

        try {
            $client = new Client(
                httpClient: $httpClient,
                requestFactory: $factory,
                streamFactory: $factory,
            );
            $result = $client->sessions()->list();
            self::assertCount(1, $result->items);
        } finally {
            if ($original !== false) {
                putenv('FOIL_SECRET_KEY=' . $original);
            } else {
                putenv('FOIL_SECRET_KEY');
            }
        }
    }

    public function testDefersMissingSecretKeyErrorUntilRequest(): void
    {
        $original = getenv('FOIL_SECRET_KEY');
        putenv('FOIL_SECRET_KEY');

        try {
            $factory = new Psr17Factory();
            $client = new Client(
                httpClient: new TestHttpClient(static fn (RequestInterface $request) => JsonResponse::create([])),
                requestFactory: $factory,
                streamFactory: $factory,
            );
            self::assertNotNull($client->sessions());
        } finally {
            if ($original !== false) {
                putenv('FOIL_SECRET_KEY=' . $original);
            }
        }
    }

    public function testSecretEndpointsFailAtRequestTimeWhenNoSecretIsConfigured(): void
    {
        $original = getenv('FOIL_SECRET_KEY');
        putenv('FOIL_SECRET_KEY');

        try {
            $factory = new Psr17Factory();
            $client = new Client(
                httpClient: new TestHttpClient(static fn (RequestInterface $request) => JsonResponse::create([])),
                requestFactory: $factory,
                streamFactory: $factory,
            );
            $this->expectException(FoilConfigurationError::class);
            $client->sessions()->list();
        } finally {
            if ($original !== false) {
                putenv('FOIL_SECRET_KEY=' . $original);
            }
        }
    }

    public function testAutodiscoveryCanBuildTheClient(): void
    {
        self::assertInstanceOf(Client::class, new Client(secretKey: 'sk_live_test'));
    }

    public function testListsSessionsWithNormalizedPaginationAndAuthHeaders(): void
    {
        $fixture = FixtureLoader::load('api/sessions/list.json');
        $factory = new Psr17Factory();
        $httpClient = new TestHttpClient(function (RequestInterface $request) use ($fixture) {
            self::assertSame('/v1/sessions', $request->getUri()->getPath());
            parse_str($request->getUri()->getQuery(), $query);
            self::assertSame('bot', $query['verdict']);
            self::assertSame('25', (string) $query['limit']);
            self::assertSame('Bearer sk_live_test', $request->getHeaderLine('Authorization'));
            self::assertSame('abxy/foil-server', $request->getHeaderLine('X-Foil-Client'));

            return JsonResponse::create($fixture);
        });

        $client = new Client(
            secretKey: 'sk_live_test',
            httpClient: $httpClient,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        $result = $client->sessions()->list(verdict: 'bot', limit: 25);
        self::assertSame(50, $result->limit);
        self::assertTrue($result->has_more);
        self::assertSame('cur_sessions_page_2', $result->next_cursor);
        self::assertSame($fixture['data'][0]['id'], $result->items[0]->id);
    }

    public function testIteratesPaginatedSessionResults(): void
    {
        $firstPage = FixtureLoader::load('api/sessions/list.json');
        $secondPage = [
            'data' => [
                [
                    ...$firstPage['data'][0],
                    'id' => 'sid_123456789abcdefghjkmnpqrst',
                    'latest_decision' => [
                        ...$firstPage['data'][0]['latest_decision'],
                        'event_id' => 'evt_3456789abcdefghjkmnpqrstvw',
                        'evaluated_at' => '2026-03-24T20:01:05.000Z',
                    ],
                ],
            ],
            'pagination' => [
                'limit' => 50,
                'has_more' => false,
            ],
            'meta' => [
                'request_id' => 'req_0123456789abcdef0123456789abcdef',
            ],
        ];

        $factory = new Psr17Factory();
        $httpClient = new TestHttpClient(static function (RequestInterface $request) use ($firstPage, $secondPage) {
            parse_str($request->getUri()->getQuery(), $query);
            return JsonResponse::create(isset($query['cursor']) ? $secondPage : $firstPage);
        });

        $client = new Client(
            secretKey: 'sk_live_test',
            httpClient: $httpClient,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        $ids = [];
        foreach ($client->sessions()->iterate(verdict: 'human') as $item) {
            $ids[] = $item->id;
        }

        self::assertSame(['sid_0123456789abcdefghjkmnpqrs', 'sid_123456789abcdefghjkmnpqrst'], $ids);
    }

    public function testFetchesSessionDetailResource(): void
    {
        $fixture = FixtureLoader::load('api/sessions/detail.json');
        $factory = new Psr17Factory();
        $httpClient = new TestHttpClient(static function (RequestInterface $request) use ($fixture) {
            self::assertStringContainsString('/v1/sessions/sid_0123456789abcdefghjkmnpqrs', (string) $request->getUri());
            return JsonResponse::create($fixture);
        });

        $client = new Client(
            secretKey: 'sk_live_test',
            httpClient: $httpClient,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        $session = $client->sessions()->get('sid_0123456789abcdefghjkmnpqrs');
        self::assertSame($fixture['data']['id'], $session->id);
        self::assertSame('user_123', $session->client_user_id);
        self::assertNull($session->native_runtime_integrity);
        self::assertNull($session->native_app);
        self::assertNull($session->native_carrier);
        self::assertNull($session->native_motion_print);
        self::assertNull($session->device_identity);
        self::assertNull($session->install_id);
    }

    public function testAttachesAndClearsClientUserId(): void
    {
        $fixture = FixtureLoader::load('api/sessions/detail.json');
        $patchBodies = [];
        $factory = new Psr17Factory();
        $httpClient = new TestHttpClient(static function (RequestInterface $request) use ($fixture, &$patchBodies) {
            self::assertSame('PATCH', $request->getMethod());
            self::assertSame('/v1/sessions/sid_0123456789abcdefghjkmnpqrs', $request->getUri()->getPath());
            self::assertSame('Bearer sk_live_test', $request->getHeaderLine('Authorization'));
            $patchBodies[] = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return JsonResponse::create($fixture);
        });

        $client = new Client(
            secretKey: 'sk_live_test',
            httpClient: $httpClient,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        self::assertSame($fixture['data']['id'], $client->sessions()->attachClientUser('sid_0123456789abcdefghjkmnpqrs', 'user_123')->id);
        self::assertSame($fixture['data']['id'], $client->sessions()->clearClientUser('sid_0123456789abcdefghjkmnpqrs')->id);
        self::assertSame([['client_user_id' => 'user_123'], ['client_user_id' => null]], $patchBodies);
    }

    public function testListsAndFetchesFingerprints(): void
    {
        $listFixture = FixtureLoader::load('api/fingerprints/list.json');
        $detailFixture = FixtureLoader::load('api/fingerprints/detail.json');
        $factory = new Psr17Factory();
        $httpClient = new TestHttpClient(static function (RequestInterface $request) use ($listFixture, $detailFixture) {
            if (str_contains((string) $request->getUri(), '/v1/fingerprints/vid_456789abcdefghjkmnpqrstvwx')) {
                return JsonResponse::create($detailFixture);
            }

            return JsonResponse::create($listFixture);
        });

        $client = new Client(
            secretKey: 'sk_live_test',
            httpClient: $httpClient,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        $page = $client->fingerprints()->list();
        self::assertFalse($page->has_more);
        self::assertSame($listFixture['data'][0]['id'], $page->items[0]->id);
        self::assertSame($detailFixture['data']['id'], $client->fingerprints()->get('vid_456789abcdefghjkmnpqrstvwx')->id);
    }

    public function testSupportsOrganizationsAndApiKeyManagementEndpoints(): void
    {
        $organizationFixture = FixtureLoader::load('api/organizations/organization.json');
        $organizationCreateFixture = FixtureLoader::load('api/organizations/organization-create.json');
        $organizationUpdateFixture = FixtureLoader::load('api/organizations/organization-update.json');
        $createKeyFixture = FixtureLoader::load('api/organizations/api-key-create.json');
        $listKeyFixture = FixtureLoader::load('api/organizations/api-key-list.json');
        $updateKeyFixture = FixtureLoader::load('api/organizations/api-key-update.json');
        $revokeKeyFixture = FixtureLoader::load('api/organizations/api-key-revoke.json');
        $rotateKeyFixture = FixtureLoader::load('api/organizations/api-key-rotate.json');

        $factory = new Psr17Factory();
        $httpClient = new TestHttpClient(static function (RequestInterface $request) use ($organizationFixture, $organizationCreateFixture, $organizationUpdateFixture, $createKeyFixture, $listKeyFixture, $updateKeyFixture, $revokeKeyFixture, $rotateKeyFixture) {
            $url = (string) $request->getUri();

            if (str_ends_with($url, '/api-keys/key_6789abcdefghjkmnpqrstvwxyz/rotations')) {
                return JsonResponse::create($rotateKeyFixture, 201);
            }
            if (str_ends_with($url, '/api-keys/key_6789abcdefghjkmnpqrstvwxyz') && $request->getMethod() === 'PATCH') {
                return JsonResponse::create($updateKeyFixture);
            }
            if (str_ends_with($url, '/api-keys/key_6789abcdefghjkmnpqrstvwxyz')) {
                return JsonResponse::create($revokeKeyFixture);
            }
            if (str_ends_with($url, '/api-keys') && $request->getMethod() === 'POST') {
                return JsonResponse::create($createKeyFixture, 201);
            }
            if (str_ends_with($url, '/api-keys')) {
                return JsonResponse::create($listKeyFixture);
            }
            if (str_ends_with($url, '/v1/organizations') && $request->getMethod() === 'POST') {
                return JsonResponse::create($organizationCreateFixture, 201);
            }
            if (str_ends_with($url, '/v1/organizations/org_56789abcdefghjkmnpqrstvwxy') && $request->getMethod() === 'PATCH') {
                return JsonResponse::create($organizationUpdateFixture);
            }

            return JsonResponse::create($organizationFixture);
        });

        $client = new Client(
            secretKey: 'sk_live_test',
            httpClient: $httpClient,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        self::assertSame($organizationFixture['data']['id'], $client->organizations()->get('org_56789abcdefghjkmnpqrstvwxy')->id);
        self::assertSame($organizationCreateFixture['data']['id'], $client->organizations()->create('Example Organization', 'example-organization')->id);
        self::assertSame($organizationUpdateFixture['data']['id'], $client->organizations()->update('org_56789abcdefghjkmnpqrstvwxy', name: 'Example Organization')->id);
        self::assertSame($createKeyFixture['data']['id'], $client->organizations()->apiKeys()->create('org_56789abcdefghjkmnpqrstvwxy', name: 'Production Backend')->id);
        self::assertSame($listKeyFixture['data'][0]['id'], $client->organizations()->apiKeys()->list('org_56789abcdefghjkmnpqrstvwxy')->items[0]->id);
        self::assertSame(
            $updateKeyFixture['data']['name'],
            $client->organizations()->apiKeys()->update('org_56789abcdefghjkmnpqrstvwxy', 'key_6789abcdefghjkmnpqrstvwxyz', name: 'Updated Web App')->name,
        );
        self::assertSame(
            $revokeKeyFixture['data']['id'],
            $client->organizations()->apiKeys()->revoke('org_56789abcdefghjkmnpqrstvwxy', 'key_6789abcdefghjkmnpqrstvwxyz')->id,
        );
        self::assertSame($rotateKeyFixture['data']['id'], $client->organizations()->apiKeys()->rotate('org_56789abcdefghjkmnpqrstvwxy', 'key_6789abcdefghjkmnpqrstvwxyz')->id);
    }

    public function testWebhooksUseEventHistoryEndpoints(): void
    {
        $delivery = [
            'object' => 'webhook_delivery',
            'id' => 'wdlv_0123456789abcdef0123456789abcdef',
            'event_id' => 'wevt_0123456789abcdef0123456789abcdef',
            'endpoint_id' => 'we_0123456789abcdef0123456789abcdef',
            'event_type' => 'session.result.persisted',
            'status' => 'succeeded',
            'attempts' => 1,
            'response_status' => 200,
            'response_body' => '{}',
            'error' => null,
            'created_at' => '2026-03-24T20:00:00.000Z',
            'updated_at' => '2026-03-24T20:00:05.000Z',
        ];
        $event = [
            'object' => 'event',
            'id' => 'wevt_0123456789abcdef0123456789abcdef',
            'type' => 'session.result.persisted',
            'subject' => ['type' => 'session', 'id' => 'sid_0123456789abcdefghjkmnpqrs'],
            'data' => ['source' => 'waitForFingerprint'],
            'webhook_deliveries' => [$delivery],
            'created_at' => '2026-03-24T20:00:00.000Z',
        ];

        $factory = new Psr17Factory();
        $httpClient = new TestHttpClient(static function (RequestInterface $request) use ($event) {
            self::assertSame('Bearer sk_live_test', $request->getHeaderLine('Authorization'));
            $path = $request->getUri()->getPath();
            if ($path === '/v1/organizations/org_56789abcdefghjkmnpqrstvwxy/events') {
                parse_str($request->getUri()->getQuery(), $query);
                self::assertSame('we_0123456789abcdef0123456789abcdef', $query['endpoint_id']);
                self::assertSame('session.result.persisted', $query['type']);
                return JsonResponse::create([
                    'data' => [$event],
                    'pagination' => ['limit' => 25, 'has_more' => false],
                    'meta' => ['request_id' => 'req_0123456789abcdef0123456789abcdef'],
                ]);
            }
            if ($path === '/v1/organizations/org_56789abcdefghjkmnpqrstvwxy/events/wevt_0123456789abcdef0123456789abcdef') {
                return JsonResponse::create([
                    'data' => $event,
                    'meta' => ['request_id' => 'req_0123456789abcdef0123456789abcdef'],
                ]);
            }

            self::fail('Unexpected request ' . $request->getMethod() . ' ' . $path);
        });

        $client = new Client(
            secretKey: 'sk_live_test',
            httpClient: $httpClient,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        $events = $client->webhooks()->listEvents(
            'org_56789abcdefghjkmnpqrstvwxy',
            'we_0123456789abcdef0123456789abcdef',
            'session.result.persisted',
            25,
        );

        self::assertSame('sid_0123456789abcdefghjkmnpqrs', $events->items[0]->subject->id);
        self::assertSame('succeeded', $events->items[0]->webhook_deliveries[0]->status);
        self::assertSame(
            'session.result.persisted',
            $client->webhooks()->retrieveEvent('org_56789abcdefghjkmnpqrstvwxy', 'wevt_0123456789abcdef0123456789abcdef')->type,
        );
    }

    public function testParsesApiErrorsIntoFoilApiError(): void
    {
        $fixture = FixtureLoader::load('errors/validation-error.json');
        $factory = new Psr17Factory();
        $httpClient = new TestHttpClient(static fn (RequestInterface $request) => JsonResponse::create(
            $fixture,
            $fixture['error']['status'],
            ['x-request-id' => $fixture['error']['request_id']],
        ));

        $client = new Client(
            secretKey: 'sk_live_test',
            httpClient: $httpClient,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        try {
            $client->sessions()->list(limit: 999);
            self::fail('Expected FoilApiError to be thrown.');
        } catch (FoilApiError $error) {
            self::assertSame(422, $error->status);
            self::assertSame($fixture['error']['code'], $error->code);
            self::assertSame($fixture['error']['request_id'], $error->request_id);
            self::assertSame($fixture['error']['details']['fields'], $error->field_errors);
            self::assertSame($fixture['error']['docs_url'] ?? null, $error->docs_url);
        }
    }
}
