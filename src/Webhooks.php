<?php

declare(strict_types=1);

namespace Foil\Server;

final class Webhooks
{
    private const WEBHOOK_EVENT_TYPES = [
        'session.fingerprint.calculated' => true,
        'session.result.persisted' => true,
        'webhook.test' => true,
    ];

    public static function verifyWebhookSignature(
        string $secret,
        string $timestamp,
        string $rawBody,
        string $signature,
        int $maxAgeSeconds = 300,
        ?int $nowSeconds = null,
    ): bool {
        if (!preg_match('/^-?\d+$/', $timestamp)) {
            return false;
        }
        $parsedTimestamp = (int) $timestamp;
        $current = $nowSeconds ?? time();
        if (abs($current - $parsedTimestamp) > $maxAgeSeconds) {
            return false;
        }
        $expected = hash_hmac('sha256', sprintf('%s.%s', $timestamp, $rawBody), $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @return array<string, mixed>
     */
    public static function parseWebhookEvent(string $rawBody): array
    {
        $value = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new \InvalidArgumentException('webhook event envelope must be an object');
        }
        if (($value['object'] ?? null) !== 'webhook_event') {
            throw new \InvalidArgumentException('webhook event object must be webhook_event');
        }
        foreach (['id', 'type', 'created'] as $field) {
            if (!is_string($value[$field] ?? null) || $value[$field] === '') {
                throw new \InvalidArgumentException(sprintf('webhook event %s is required', $field));
            }
        }
        if (!isset(self::WEBHOOK_EVENT_TYPES[$value['type']])) {
            throw new \InvalidArgumentException(sprintf('unsupported webhook event type: %s', $value['type']));
        }
        if (!is_array($value['data'] ?? null)) {
            throw new \InvalidArgumentException('webhook event data must be an object');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public static function verifyAndParseWebhookEvent(
        string $secret,
        string $timestamp,
        string $rawBody,
        string $signature,
        int $maxAgeSeconds = 300,
        ?int $nowSeconds = null,
    ): array {
        if (!self::verifyWebhookSignature($secret, $timestamp, $rawBody, $signature, $maxAgeSeconds, $nowSeconds)) {
            throw new \InvalidArgumentException('Invalid Foil webhook signature');
        }

        return self::parseWebhookEvent($rawBody);
    }
}
