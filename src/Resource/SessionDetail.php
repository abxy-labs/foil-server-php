<?php

declare(strict_types=1);

namespace Foil\Server\Resource;

final class SessionDetail
{
    /**
     * @param array<string, mixed> $decision
     * @param array<int, array<string, mixed>> $highlights
     * @param array<string, mixed>|null $attribution
     * @param array<string, mixed>|null $web_bot_auth
     * @param array<string, mixed> $network
     * @param array<string, mixed> $runtime_integrity
     * @param array<string, mixed>|null $native_runtime_integrity
     * @param array<string, mixed>|null $native_app
     * @param array<string, mixed>|null $native_carrier
     * @param array<string, mixed>|null $native_motion_print
     * @param array<string, mixed>|null $device_identity
     * @param string|null $install_id
     * @param array<string, mixed>|null $visitor_fingerprint
     * @param array<string, mixed> $connection_fingerprint
     * @param array<int, array<string, mixed>> $previous_decisions
     * @param array<string, mixed> $request
     * @param array<string, mixed> $browser
     * @param array<string, mixed> $device
     * @param array<string, mixed> $analysis_coverage
     * @param array<int, array<string, mixed>> $signals_fired
     * @param array<string, mixed> $client_telemetry
     */
    public function __construct(
        public readonly string $object,
        public readonly string $id,
        public readonly ?string $created_at,
        public readonly ?string $client_user_id,
        public readonly array $decision,
        public readonly array $highlights,
        public readonly ?array $attribution,
        public readonly ?array $web_bot_auth,
        public readonly array $network,
        public readonly array $runtime_integrity,
        public readonly ?array $native_runtime_integrity,
        public readonly ?array $native_app,
        public readonly ?array $native_carrier,
        public readonly ?array $native_motion_print,
        public readonly ?array $device_identity,
        public readonly ?string $install_id,
        public readonly ?array $visitor_fingerprint,
        public readonly array $connection_fingerprint,
        public readonly array $previous_decisions,
        public readonly array $request,
        public readonly array $browser,
        public readonly array $device,
        public readonly array $analysis_coverage,
        public readonly array $signals_fired,
        public readonly array $client_telemetry,
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
            (array) $data['decision'],
            array_map(static fn (mixed $item): array => (array) $item, (array) ($data['highlights'] ?? [])),
            isset($data['attribution']) && is_array($data['attribution']) ? $data['attribution'] : null,
            isset($data['web_bot_auth']) && is_array($data['web_bot_auth']) ? $data['web_bot_auth'] : null,
            isset($data['network']) && is_array($data['network']) ? $data['network'] : [],
            isset($data['runtime_integrity']) && is_array($data['runtime_integrity']) ? $data['runtime_integrity'] : [],
            isset($data['native_runtime_integrity']) && is_array($data['native_runtime_integrity']) ? $data['native_runtime_integrity'] : null,
            isset($data['native_app']) && is_array($data['native_app']) ? $data['native_app'] : null,
            isset($data['native_carrier']) && is_array($data['native_carrier']) ? $data['native_carrier'] : null,
            isset($data['native_motion_print']) && is_array($data['native_motion_print']) ? $data['native_motion_print'] : null,
            isset($data['device_identity']) && is_array($data['device_identity']) ? $data['device_identity'] : null,
            isset($data['install_id']) ? (string) $data['install_id'] : null,
            isset($data['visitor_fingerprint']) && is_array($data['visitor_fingerprint']) ? $data['visitor_fingerprint'] : null,
            isset($data['connection_fingerprint']) && is_array($data['connection_fingerprint']) ? $data['connection_fingerprint'] : [],
            array_map(static fn (mixed $item): array => (array) $item, (array) ($data['previous_decisions'] ?? [])),
            (array) $data['request'],
            isset($data['browser']) && is_array($data['browser']) ? $data['browser'] : [],
            isset($data['device']) && is_array($data['device']) ? $data['device'] : [],
            isset($data['analysis_coverage']) && is_array($data['analysis_coverage']) ? $data['analysis_coverage'] : [],
            array_map(static fn (mixed $item): array => (array) $item, (array) ($data['signals_fired'] ?? [])),
            isset($data['client_telemetry']) && is_array($data['client_telemetry']) ? $data['client_telemetry'] : [],
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
            'decision' => $this->decision,
            'highlights' => $this->highlights,
            'attribution' => $this->attribution,
            'web_bot_auth' => $this->web_bot_auth,
            'network' => $this->network,
            'runtime_integrity' => $this->runtime_integrity,
            'native_runtime_integrity' => $this->native_runtime_integrity,
            'native_app' => $this->native_app,
            'native_carrier' => $this->native_carrier,
            'native_motion_print' => $this->native_motion_print,
            'device_identity' => $this->device_identity,
            'install_id' => $this->install_id,
            'visitor_fingerprint' => $this->visitor_fingerprint,
            'connection_fingerprint' => $this->connection_fingerprint,
            'previous_decisions' => $this->previous_decisions,
            'request' => $this->request,
            'browser' => $this->browser,
            'device' => $this->device,
            'analysis_coverage' => $this->analysis_coverage,
            'signals_fired' => $this->signals_fired,
            'client_telemetry' => $this->client_telemetry,
        ];
    }
}
