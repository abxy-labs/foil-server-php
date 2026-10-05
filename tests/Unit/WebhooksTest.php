<?php

declare(strict_types=1);

namespace Foil\Server\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Foil\Server\Tests\Support\FixtureLoader;
use Foil\Server\Webhooks;

final class WebhooksTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureLoader::load('webhooks/signature.json');
    }

    public function testValidSignatureVerifies(): void
    {
        self::assertTrue(Webhooks::verifyWebhookSignature(
            $this->fixture['secret'],
            $this->fixture['timestamp'],
            $this->fixture['raw_body'],
            $this->fixture['signature'],
            nowSeconds: $this->fixture['now_seconds'],
        ));
    }

    public function testTamperedSignatureBodyOrSecretIsRejected(): void
    {
        $verify = fn (string $secret, string $body, string $signature): bool => Webhooks::verifyWebhookSignature(
            $secret,
            $this->fixture['timestamp'],
            $body,
            $signature,
            nowSeconds: $this->fixture['now_seconds'],
        );

        self::assertFalse($verify($this->fixture['secret'], $this->fixture['raw_body'], $this->fixture['invalid_signature']));
        self::assertFalse($verify($this->fixture['secret'], $this->fixture['raw_body'], 'short'));
        self::assertFalse($verify($this->fixture['secret'], $this->fixture['raw_body'] . ' ', $this->fixture['signature']));
        self::assertFalse($verify('whsec_other', $this->fixture['raw_body'], $this->fixture['signature']));
    }

    public function testEmptySecretIsRejected(): void
    {
        $signature = hash_hmac('sha256', $this->fixture['timestamp'] . '.' . $this->fixture['raw_body'], '');

        self::assertFalse(Webhooks::verifyWebhookSignature(
            '',
            $this->fixture['timestamp'],
            $this->fixture['raw_body'],
            $signature,
            nowSeconds: $this->fixture['now_seconds'],
        ));
    }

    public function testExpiredAndMalformedTimestampsAreRejected(): void
    {
        self::assertFalse(Webhooks::verifyWebhookSignature(
            $this->fixture['secret'],
            $this->fixture['expired_timestamp'],
            $this->fixture['raw_body'],
            $this->fixture['signature'],
            nowSeconds: $this->fixture['now_seconds'],
        ));
        self::assertFalse(Webhooks::verifyWebhookSignature(
            $this->fixture['secret'],
            'not-a-timestamp',
            $this->fixture['raw_body'],
            $this->fixture['signature'],
            nowSeconds: $this->fixture['now_seconds'],
        ));
    }

    public function testCustomMaxAgeIsHonored(): void
    {
        self::assertTrue(Webhooks::verifyWebhookSignature(
            $this->fixture['secret'],
            $this->fixture['timestamp'],
            $this->fixture['raw_body'],
            $this->fixture['signature'],
            maxAgeSeconds: 900,
            nowSeconds: $this->fixture['now_seconds'] + 600,
        ));
    }

    public function testParsesSessionResultPersistedEvent(): void
    {
        $event = Webhooks::parseWebhookEvent($this->fixture['raw_body']);

        self::assertSame('webhook_event', $event['object']);
        self::assertSame('session.result.persisted', $event['type']);
        self::assertSame('wevt_0123456789abcdef0123456789abcdef', $event['id']);
        self::assertSame('sid_0123456789abcdefghjkmnpqrs', $event['data']['session']['id']);
    }

    public function testParsesWebhookTestEvent(): void
    {
        $event = Webhooks::parseWebhookEvent(json_encode([
            'id' => 'wevt_0123456789abcdefghjkmnpqrs',
            'object' => 'webhook_event',
            'type' => 'webhook.test',
            'created' => '2026-04-27T00:00:00.000Z',
            'data' => [],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('webhook.test', $event['type']);
    }

    public function testRejectsUnsupportedOrMalformedEvents(): void
    {
        $base = [
            'id' => 'wevt_0123456789abcdefghjkmnpqrs',
            'object' => 'webhook_event',
            'type' => 'session.result.persisted',
            'created' => '2026-04-27T00:00:00.000Z',
            'data' => [],
        ];

        foreach ([
            'unsupported webhook event type' => ['type' => 'unknown.event'],
            'unsupported webhook event type: session.fingerprint.calculated' => ['type' => 'session.fingerprint.calculated'],
            'webhook_event' => ['object' => 'event'],
            'data must be an object' => ['data' => 'nope'],
        ] as $message => $override) {
            try {
                Webhooks::parseWebhookEvent(json_encode(array_merge($base, $override), JSON_THROW_ON_ERROR));
                self::fail('Expected InvalidArgumentException for ' . $message);
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        Webhooks::parseWebhookEvent('"not an object"');
    }

    public function testVerifyAndParseChecksSignatureFirst(): void
    {
        $event = Webhooks::verifyAndParseWebhookEvent(
            $this->fixture['secret'],
            $this->fixture['timestamp'],
            $this->fixture['raw_body'],
            $this->fixture['signature'],
            nowSeconds: $this->fixture['now_seconds'],
        );
        self::assertSame('session.result.persisted', $event['type']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Foil webhook signature');
        Webhooks::verifyAndParseWebhookEvent(
            $this->fixture['secret'],
            $this->fixture['timestamp'],
            $this->fixture['raw_body'],
            $this->fixture['invalid_signature'],
            nowSeconds: $this->fixture['now_seconds'],
        );
    }
}
