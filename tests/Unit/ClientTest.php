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

    public function testThrowsWhenNoSecretKeyIsConfigured(): void
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
            self::assertNotNull($client->gate());
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
            'event_type' => 'session.fingerprint.calculated',
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
            'type' => 'session.fingerprint.calculated',
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
                self::assertSame('session.fingerprint.calculated', $query['type']);
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
            'session.fingerprint.calculated',
            25,
        );

        self::assertSame('sid_0123456789abcdefghjkmnpqrs', $events->items[0]->subject->id);
        self::assertSame('succeeded', $events->items[0]->webhook_deliveries[0]->status);
        self::assertSame(
            'session.fingerprint.calculated',
            $client->webhooks()->retrieveEvent('org_56789abcdefghjkmnpqrstvwxy', 'wevt_0123456789abcdef0123456789abcdef')->type,
        );
    }

    public function testSupportsGateEndpointsAcrossPublicBearerAndSecretFlows(): void
    {
        $registryListFixture = FixtureLoader::load('api/gate/registry-list.json');
        $registryDetailFixture = FixtureLoader::load('api/gate/registry-detail.json');
        $servicesListFixture = FixtureLoader::load('api/gate/services-list.json');
        $serviceDetailFixture = FixtureLoader::load('api/gate/service-detail.json');
        $serviceCreateFixture = FixtureLoader::load('api/gate/service-create.json');
        $serviceUpdateFixture = FixtureLoader::load('api/gate/service-update.json');
        $serviceDisableFixture = FixtureLoader::load('api/gate/service-disable.json');
        $sessionCreateFixture = FixtureLoader::load('api/gate/session-create.json');
        $sessionPollFixture = FixtureLoader::load('api/gate/session-poll.json');
        $sessionAckFixture = FixtureLoader::load('api/gate/session-ack.json');
        $loginCreateFixture = FixtureLoader::load('api/gate/login-session-create.json');
        $loginConsumeFixture = FixtureLoader::load('api/gate/login-session-consume.json');
        $agentVerifyFixture = FixtureLoader::load('api/gate/agent-token-verify.json');

        $factory = new Psr17Factory();
        $httpClient = new TestHttpClient(static function (RequestInterface $request) use (
            $registryListFixture,
            $registryDetailFixture,
            $servicesListFixture,
            $serviceDetailFixture,
            $serviceCreateFixture,
            $serviceUpdateFixture,
            $serviceDisableFixture,
            $sessionCreateFixture,
            $sessionPollFixture,
            $sessionAckFixture,
            $loginCreateFixture,
            $loginConsumeFixture,
            $agentVerifyFixture,
        ) {
            $path = $request->getUri()->getPath();
            $auth = $request->getHeaderLine('Authorization');

            if ($path === '/v1/gate/registry') {
                self::assertSame('', $auth);
                return JsonResponse::create($registryListFixture);
            }
            if ($path === '/v1/gate/registry/foil') {
                self::assertSame('', $auth);
                return JsonResponse::create($registryDetailFixture);
            }
            if ($path === '/v1/gate/services' && $request->getMethod() === 'GET') {
                self::assertSame('Bearer sk_live_test', $auth);
                return JsonResponse::create($servicesListFixture);
            }
            if ($path === '/v1/gate/services/foil' && $request->getMethod() === 'GET') {
                self::assertSame('Bearer sk_live_test', $auth);
                return JsonResponse::create($serviceDetailFixture);
            }
            if ($path === '/v1/gate/services' && $request->getMethod() === 'POST') {
                self::assertSame('Bearer sk_live_test', $auth);
                return JsonResponse::create($serviceCreateFixture, 201);
            }
            if ($path === '/v1/gate/services/acme_prod' && $request->getMethod() === 'PATCH') {
                self::assertSame('Bearer sk_live_test', $auth);
                return JsonResponse::create($serviceUpdateFixture);
            }
            if ($path === '/v1/gate/services/acme_prod' && $request->getMethod() === 'DELETE') {
                self::assertSame('Bearer sk_live_test', $auth);
                return JsonResponse::create($serviceDisableFixture);
            }
            if ($path === '/v1/gate/sessions') {
                self::assertSame('', $auth);
                return JsonResponse::create($sessionCreateFixture, 201);
            }
            if ($path === '/v1/gate/sessions/gate_0123456789abcdefghjkmnpqrs' && $request->getMethod() === 'GET') {
                self::assertSame('Bearer gtpoll_0123456789abcdefghjkmnpqrs', $auth);
                return JsonResponse::create($sessionPollFixture);
            }
            if ($path === '/v1/gate/sessions/gate_0123456789abcdefghjkmnpqrs/ack') {
                self::assertSame('Bearer gtpoll_0123456789abcdefghjkmnpqrs', $auth);
                return JsonResponse::create($sessionAckFixture);
            }
            if ($path === '/v1/gate/login-sessions') {
                self::assertSame('Bearer agt_0123456789abcdefghjkmnpqrs', $auth);
                return JsonResponse::create($loginCreateFixture, 201);
            }
            if ($path === '/v1/gate/login-sessions/consume') {
                self::assertSame('Bearer sk_live_test', $auth);
                return JsonResponse::create($loginConsumeFixture);
            }
            if ($path === '/v1/gate/agent-tokens/verify') {
                self::assertSame('Bearer sk_live_test', $auth);
                return JsonResponse::create($agentVerifyFixture);
            }
            if ($path === '/v1/gate/agent-tokens/revoke') {
                self::assertSame('Bearer sk_live_test', $auth);
                return JsonResponse::create(null, 204);
            }

            self::fail('Unexpected request ' . $request->getMethod() . ' ' . $path);
        });

        $client = new Client(
            secretKey: 'sk_live_test',
            httpClient: $httpClient,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        self::assertSame('foil', $client->gate()->registry()->list()[0]->id);
        self::assertSame('foil', $client->gate()->registry()->get('foil')->id);
        self::assertSame('acme_prod', $client->gate()->services()->list()[0]->id);
        self::assertSame('acme_prod', $client->gate()->services()->get('foil')->id);
        self::assertSame(
            'acme_prod',
            $client->gate()->services()->create(
                'acme_prod',
                'Acme Production',
                'Acme production signup flow',
                'https://acme.example.com',
                'we_0123456789abcdef0123456789abcdef',
            )->id,
        );
        self::assertTrue($client->gate()->services()->update('acme_prod', discoverable: true)->discoverable);
        self::assertSame('disabled', $client->gate()->services()->disable('acme_prod')->status);
        self::assertSame(
            'gate_0123456789abcdefghjkmnpqrs',
            $client->gate()->sessions()->create(
                'foil',
                'my-project',
                [
                    'version' => 1,
                    'algorithm' => 'x25519-hkdf-sha256/aes-256-gcm',
                    'key_id' => 'kid_integrator_0123456789abcdefgh',
                    'public_key' => 'public_key_integrator',
                ],
            )->id,
        );
        self::assertSame('approved', $client->gate()->sessions()->poll('gate_0123456789abcdefghjkmnpqrs', 'gtpoll_0123456789abcdefghjkmnpqrs')->status);
        self::assertSame('acknowledged', $client->gate()->sessions()->acknowledge('gate_0123456789abcdefghjkmnpqrs', 'gtpoll_0123456789abcdefghjkmnpqrs', 'gtack_0123456789abcdefghjkmnpqrs')->status);
        self::assertSame('gate_login_session', $client->gate()->loginSessions()->create('foil', 'agt_0123456789abcdefghjkmnpqrs')->object);
        self::assertSame('gate_dashboard_login', $client->gate()->loginSessions()->consume('gate_code_0123456789abcdefghjkm')->object);
        self::assertTrue($client->gate()->agentTokens()->verify('agt_0123456789abcdefghjkmnpqrs')->valid);
        self::assertNull($client->gate()->agentTokens()->revoke('agt_0123456789abcdefghjkmnpqrs'));
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
