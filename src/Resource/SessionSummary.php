<?php

declare(strict_types=1);

namespace Foil\Server\Resource;

final class SessionSummary
{
    /**
     * @param array<string, mixed> $latest_decision
     * @param array<string, mixed>|null $visitor_fingerprint
     */
    public function __construct(
        public readonly string $object,
        public readonly string $id,
        public readonly ?string $created_at,
        public readonly ?string $client_user_id,
        public readonly array $latest_decision,
        public readonly ?array $visitor_fingerprint,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['object'],
            (string) $data['id'],
            isset($data['created_at']) ? (string) $data['created_at'] : null,
            isset($data['client_user_id']) ? (string) $data['client_user_id'] : null,
            (array) $data['latest_decision'],
            isset($data['visitor_fingerprint']) && is_array($data['visitor_fingerprint']) ? $data['visitor_fingerprint'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'object' => $this->object,
            'id' => $this->id,
            'created_at' => $this->created_at,
            'client_user_id' => $this->client_user_id,
            'latest_decision' => $this->latest_decision,
            'visitor_fingerprint' => $this->visitor_fingerprint,
        ];
    }
}
