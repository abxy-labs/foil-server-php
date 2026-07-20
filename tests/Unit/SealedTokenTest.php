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
