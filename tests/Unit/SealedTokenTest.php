<?php

declare(strict_types=1);

namespace Foil\Server\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Foil\Server\Exception\FoilConfigurationError;
use Foil\Server\Exception\FoilTokenVerificationError;
use Foil\Server\SealedToken;
use Foil\Server\Tests\Support\FixtureLoader;

final class SealedTokenTest extends TestCase
{
    private const CAP_TEST_SECRET = 'sk_live_cap_test';

    /**
     * @param array<string, mixed> $payload
     */
    private static function sealLegacyToken(array $payload, string $secretKey): string
    {
        $key = hash('sha256', hash('sha256', $secretKey) . "\0sealed-results", true);
        $nonce = random_bytes(12);
        $tag = '';
        $compressed = gzcompress(json_encode($payload, JSON_THROW_ON_ERROR));
        $ciphertext = openssl_encrypt($compressed, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        self::assertIsString($ciphertext);

        return base64_encode("\x01" . $nonce . $ciphertext . $tag);
    }

    public function testAcceptsPayloadJustUnderSizeCap(): void
    {
        $payload = FixtureLoader::load('sealed-token/vector.v1.json')['payload'];
        $payload['padding'] = str_repeat(
            'a',
            SealedToken::MAX_PAYLOAD_BYTES - strlen(json_encode($payload, JSON_THROW_ON_ERROR)) - 64,
        );
        $verified = SealedToken::verify(self::sealLegacyToken($payload, self::CAP_TEST_SECRET), self::CAP_TEST_SECRET);

        self::assertSame($payload['session_id'], $verified->session_id);
    }

    public function testRejectsAuthenticTokenThatDecompressesPastSizeCap(): void
    {
        $payload = FixtureLoader::load('sealed-token/vector.v1.json')['payload'];
        $payload['padding'] = str_repeat('a', 2 * 1048576);

        $this->expectException(FoilTokenVerificationError::class);
        $this->expectExceptionMessage('Foil token payload is too large.');
        SealedToken::verify(self::sealLegacyToken($payload, self::CAP_TEST_SECRET), self::CAP_TEST_SECRET);
    }

    public function testVerifyVectorWithPlaintextSecret(): void
    {
        $fixture = FixtureLoader::load('sealed-token/vector.v1.json');
        $verified = SealedToken::verify($fixture['token'], $fixture['secretKey']);

        self::assertSame($fixture['payload']['session_id'], $verified->session_id);
        self::assertSame($fixture['payload'], $verified->toArray());
    }

    public function testVerifyVectorWithSecretHash(): void
    {
        $fixture = FixtureLoader::load('sealed-token/vector.v1.json');
        $verified = SealedToken::verify($fixture['token'], $fixture['secretHash']);

        self::assertSame($fixture['payload'], $verified->toArray());
    }

    public function testVerifyMultiRecipientVectorWithEveryActiveKey(): void
    {
        $fixture = FixtureLoader::load('sealed-token/vector.v2.json');
        foreach (array_merge($fixture['secretKeys'], $fixture['secretHashes']) as $secret) {
            $verified = SealedToken::verify($fixture['token'], $secret);
            self::assertSame($fixture['payload'], $verified->toArray());
        }
    }

    public function testInvalidTokenReturnsFailureResult(): void
    {
        $fixture = FixtureLoader::load('sealed-token/invalid.json');
        $result = SealedToken::safeVerify($fixture['token'], 'sk_live_fixture_secret');

        self::assertFalse($result->ok);
        self::assertInstanceOf(FoilTokenVerificationError::class, $result->error);
    }

    public function testMissingSecretRaisesConfigurationError(): void
    {
        $fixture = FixtureLoader::load('sealed-token/vector.v1.json');
        $original = getenv('FOIL_SECRET_KEY');
        putenv('FOIL_SECRET_KEY');

        try {
            $this->expectException(FoilConfigurationError::class);
            SealedToken::verify($fixture['token']);
        } finally {
            if ($original !== false) {
                putenv('FOIL_SECRET_KEY=' . $original);
            } else {
                putenv('FOIL_SECRET_KEY');
            }
        }
    }
}
