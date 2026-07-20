<?php

declare(strict_types=1);

namespace Foil\Server;

use JsonException;
use Throwable;
use Foil\Server\Exception\FoilConfigurationError;
use Foil\Server\Exception\FoilTokenVerificationError;
use Foil\Server\Resource\VerifiedFoilToken;
use Foil\Server\Result\SafeVerifyResult;

final class SealedToken
{
    private const LEGACY_VERSION = 0x01;
    private const MULTI_RECIPIENT_VERSION = 0x02;
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;
    private const CONTENT_KEY_BYTES = 32;
    private const RECIPIENT_ID_BYTES = 32;
    private const MAX_RECIPIENTS = 256;
    private const V2_HEADER_BYTES = 19;
    private const V2_RECIPIENT_BYTES = 92;
    private const V2_PAYLOAD_AAD_PREFIX = "foil-sealed-results-v2\0payload\0";
    private const V2_WRAP_AAD_PREFIX = "foil-sealed-results-v2\0recipient\0";

    public static function verify(string $sealedToken, ?string $secretKey = null): VerifiedFoilToken
    {
        $resolvedSecret = self::resolveSecretKey($secretKey);

        $buffer = base64_decode($sealedToken, true);
        if ($buffer === false || strlen($buffer) < 29) {
            throw new FoilTokenVerificationError('Foil token is too short.');
        }

        try {
            $plaintext = self::decryptPayload($buffer, $resolvedSecret);
        } catch (Throwable $exception) {
            throw new FoilTokenVerificationError('Failed to verify Foil token.', $exception);
        }

        if (!is_string($plaintext)) {
            throw new FoilTokenVerificationError('Failed to verify Foil token.');
        }

        $inflated = zlib_decode($plaintext);
        if (!is_string($inflated)) {
            throw new FoilTokenVerificationError('Failed to verify Foil token.');
        }

        try {
            $payload = json_decode($inflated, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FoilTokenVerificationError('Failed to verify Foil token.', $exception);
        }

        if (!is_array($payload)) {
            throw new FoilTokenVerificationError('Failed to verify Foil token.');
        }

        return VerifiedFoilToken::fromArray($payload);
    }

    public static function safeVerify(string $sealedToken, ?string $secretKey = null): SafeVerifyResult
    {
        try {
            return SafeVerifyResult::success(self::verify($sealedToken, $secretKey));
        } catch (FoilConfigurationError|FoilTokenVerificationError $exception) {
            return SafeVerifyResult::failure($exception);
        } catch (Throwable $exception) {
            return SafeVerifyResult::failure(new FoilTokenVerificationError('Failed to verify Foil token.', $exception));
        }
    }

    private static function resolveSecretKey(?string $secretKey): string
    {
        $resolved = $secretKey;
        if ($resolved === null || $resolved === '') {
            $resolved = getenv('FOIL_SECRET_KEY') ?: null;
        }

        if ($resolved === null || $resolved === '') {
            throw new FoilConfigurationError(
                'Missing Foil secret key. Pass secretKey explicitly or set FOIL_SECRET_KEY.',
            );
        }

        return $resolved;
    }

    private static function deriveKey(string $secretKeyOrHash): string
    {
        return hash('sha256', self::normalizeSecret($secretKeyOrHash) . "\0sealed-results", true);
    }

    private static function normalizeSecret(string $secretKeyOrHash): string
    {
        return preg_match('/^[0-9a-f]{64}$/i', $secretKeyOrHash) === 1
            ? strtolower($secretKeyOrHash)
            : hash('sha256', $secretKeyOrHash);
    }

    private static function decryptGcm(
        string $ciphertext,
        string $key,
        string $nonce,
        string $tag,
        string $aad = '',
    ): string {
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
        );
        if (!is_string($plaintext)) {
            throw new FoilTokenVerificationError('Failed to verify Foil token.');
        }
        return $plaintext;
    }

    private static function decryptPayload(string $buffer, string $secretKey): string
    {
        $version = ord($buffer[0]);
        if ($version === self::LEGACY_VERSION) {
            return self::decryptGcm(
                substr($buffer, 13, -self::TAG_BYTES),
                self::deriveKey($secretKey),
                substr($buffer, 1, self::NONCE_BYTES),
                substr($buffer, -self::TAG_BYTES),
            );
        }
        if ($version !== self::MULTI_RECIPIENT_VERSION) {
            throw new FoilTokenVerificationError(sprintf('Unsupported Foil token version: %d', $version));
        }
        if (strlen($buffer) < self::V2_HEADER_BYTES + self::TAG_BYTES + self::V2_RECIPIENT_BYTES) {
            throw new FoilTokenVerificationError('Foil token is too short.');
        }

        $recipientCount = unpack('n', substr($buffer, 1, 2))[1];
        if ($recipientCount < 1 || $recipientCount > self::MAX_RECIPIENTS) {
            throw new FoilTokenVerificationError('Foil token has an invalid recipient count.');
        }
        $payloadLength = unpack('N', substr($buffer, 15, 4))[1];
        $payloadStart = self::V2_HEADER_BYTES;
        $payloadTagStart = $payloadStart + $payloadLength;
        $recipientsStart = $payloadTagStart + self::TAG_BYTES;
        if (
            $payloadLength < 1
            || $recipientsStart + ($recipientCount * self::V2_RECIPIENT_BYTES) !== strlen($buffer)
        ) {
            throw new FoilTokenVerificationError('Foil token has an invalid length.');
        }

        $expectedId = hash(
            'sha256',
            self::normalizeSecret($secretKey) . "\0sealed-results-recipient-id",
            true,
        );
        $recipientIds = '';
        for ($index = 0; $index < $recipientCount; $index++) {
            $recipientIds .= substr(
                $buffer,
                $recipientsStart + ($index * self::V2_RECIPIENT_BYTES),
                self::RECIPIENT_ID_BYTES,
            );
        }
        $contentKey = null;
        for ($index = 0; $index < $recipientCount; $index++) {
            $entryStart = $recipientsStart + ($index * self::V2_RECIPIENT_BYTES);
            $recipientId = substr($buffer, $entryStart, self::RECIPIENT_ID_BYTES);
            if (!hash_equals($expectedId, $recipientId)) {
                continue;
            }
            $nonceStart = $entryStart + self::RECIPIENT_ID_BYTES;
            $wrappedKeyStart = $nonceStart + self::NONCE_BYTES;
            $tagStart = $wrappedKeyStart + self::CONTENT_KEY_BYTES;
            $contentKey = self::decryptGcm(
                substr($buffer, $wrappedKeyStart, self::CONTENT_KEY_BYTES),
                self::deriveKey($secretKey),
                substr($buffer, $nonceStart, self::NONCE_BYTES),
                substr($buffer, $tagStart, self::TAG_BYTES),
                self::V2_WRAP_AAD_PREFIX . $recipientId,
            );
            break;
        }
        if (!is_string($contentKey) || strlen($contentKey) !== self::CONTENT_KEY_BYTES) {
            throw new FoilTokenVerificationError('Secret key is not a recipient of this Foil token.');
        }

        return self::decryptGcm(
            substr($buffer, $payloadStart, $payloadLength),
            $contentKey,
            substr($buffer, 3, self::NONCE_BYTES),
            substr($buffer, $payloadTagStart, self::TAG_BYTES),
            self::V2_PAYLOAD_AAD_PREFIX . substr($buffer, 0, self::V2_HEADER_BYTES) . $recipientIds,
        );
    }
}
