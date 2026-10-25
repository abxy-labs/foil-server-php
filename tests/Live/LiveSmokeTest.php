<?php

declare(strict_types=1);

namespace Foil\Server\Tests\Live;

use PHPUnit\Framework\TestCase;
use Foil\Server\Client;
use Foil\Server\Exception\FoilApiError;
use Foil\Server\Resource\ApiKey;
use Foil\Server\SealedToken;
use Foil\Server\Tests\Support\FixtureLoader;

final class LiveSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        if ((getenv('FOIL_LIVE_SMOKE') ?: '') !== '1') {
            $this->markTestSkipped('Set FOIL_LIVE_SMOKE=1 to run live smoke tests.');
        }
    }

    public function testPublicServerSurface(): void
    {
        $client = new Client(
            secretKey: $this->requireEnv('FOIL_SMOKE_SECRET_KEY'),
            baseUrl: getenv('FOIL_SMOKE_BASE_URL') ?: 'https://api.usefoil.com',
        );
        $organizationId = $this->requireEnv('FOIL_SMOKE_ORGANIZATION_ID');

        $createdKeyId = null;
        $rotatedKeyId = null;

        try {
            $sessions = $client->sessions()->list(limit: 1);
            self::assertGreaterThan(0, count($sessions->items), 'Smoke organization must have at least one session for the live smoke suite.');
            $session = $client->sessions()->get($sessions->items[0]->id);
            self::assertSame($sessions->items[0]->id, $session->id);

            $fingerprints = $client->fingerprints()->list(limit: 1);
            self::assertGreaterThan(0, count($fingerprints->items), 'Smoke organization must have at least one fingerprint for the live smoke suite.');
            $fingerprint = $client->fingerprints()->get($fingerprints->items[0]->id);
            self::assertSame($fingerprints->items[0]->id, $fingerprint->id);

            $organization = $client->organizations()->get($organizationId);
            self::assertSame($organizationId, $organization->id);
            $updatedOrganization = $client->organizations()->update($organizationId, name: $organization->name, status: $organization->status);
            self::assertSame($organization->name, $updatedOrganization->name);
            self::assertSame($organization->status, $updatedOrganization->status);

            $created = $client->organizations()->apiKeys()->create(
                $organizationId,
                name: 'sdk-smoke-' . base_convert((string) (int) floor(microtime(true) * 1000), 10, 36),
                environment: 'test',
            );
            $createdKeyId = $created->id;
            self::assertTrue(str_starts_with($created->revealed_key, 'pk_test_'), 'Created API key does not start with pk_test_.');

            $listedKey = $this->findApiKey($client, $organizationId, $created->id);
            self::assertNotNull($listedKey);
            self::assertSame($created->id, $listedKey?->id);

            $rotated = $client->organizations()->apiKeys()->rotate($organizationId, $created->id);
            $rotatedKeyId = $rotated->id;
            self::assertTrue(str_starts_with($rotated->revealed_key, 'pk_test_'), 'Rotated API key does not start with pk_test_.');

            $fixture = FixtureLoader::load('sealed-token/vector.v1.json');
            $verified = SealedToken::safeVerify($fixture['token'], $fixture['secretKey']);
            self::assertTrue($verified->ok);
            self::assertSame($fixture['payload']['session_id'], $verified->data?->session_id);
        } finally {
            $this->bestEffortRevoke($client, $organizationId, $rotatedKeyId);
            if ($createdKeyId !== $rotatedKeyId) {
                $this->bestEffortRevoke($client, $organizationId, $createdKeyId);
            }
        }
    }

    private function requireEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new \RuntimeException($name . ' is required for the live smoke suite.');
        }

        return $value;
    }

    private function bestEffortRevoke(Client $client, string $organizationId, ?string $keyId): void
    {
        if ($keyId === null || $keyId === '') {
            return;
        }

        try {
            $client->organizations()->apiKeys()->revoke($organizationId, $keyId);
        } catch (FoilApiError $error) {
            if ($error->status === 404 || $error->code === 'request.not_found') {
                return;
            }

            throw $error;
        }
    }

    private function findApiKey(Client $client, string $organizationId, string $keyId): ?ApiKey
    {
        $cursor = null;

        do {
            $page = $client->organizations()->apiKeys()->list($organizationId, limit: 100, cursor: $cursor);
            foreach ($page->items as $item) {
                if ($item->id === $keyId) {
                    return $item;
                }
            }
            $cursor = $page->has_more ? $page->next_cursor : null;
        } while ($cursor !== null);

        return null;
    }
}
