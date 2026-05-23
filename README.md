# Foil PHP Library

![Preview](https://img.shields.io/badge/status-preview-111827)
![PHP 8.1+](https://img.shields.io/badge/php-%E2%89%A58.1-777BB4?logo=php&logoColor=white)
![License: MIT](https://img.shields.io/badge/license-MIT-0f766e.svg)

The Foil PHP library provides convenient access to the Foil API from applications written in PHP. It includes a framework-agnostic client for Sessions, visitor fingerprints, Organizations, Organization API key management, sealed token verification, Gate, and Gate delivery/webhook helpers.

The library also provides:

- a fast configuration path using `FOIL_SECRET_KEY`
- a bundled PSR-18 transport stack with support for custom PSR clients and factories
- structured API errors and built-in sealed token verification
- webhook endpoint management, test sends, and event delivery history
- public, bearer-token, and secret-key auth modes for Gate flows
- Gate delivery/webhook helpers

## Documentation

See the [Foil docs](https://usefoil.com/docs) and [API reference](https://usefoil.com/docs/api-reference/introduction).

## Installation

You don't need this source code unless you want to modify the package. If you just want to use the package, run:

```bash
composer require abxy/foil-server
```

## Requirements

- PHP 8.1+

## Usage

Use `FOIL_SECRET_KEY` or `secretKey` for core detect APIs. For public or bearer-auth Gate flows, the client can also be created without a secret key:

```php
<?php

use Foil\Server\Client;

$client = new Client(secretKey: getenv('FOIL_SECRET_KEY') ?: null);

$page = $client->sessions()->list(verdict: 'bot', limit: 25);
$session = $client->sessions()->get('sid_0123456789abcdefghjkmnpqrs');
$client->sessions()->attachClientUser('sid_0123456789abcdefghjkmnpqrs', 'user_123');
$client->sessions()->clearClientUser('sid_0123456789abcdefghjkmnpqrs');

echo $session->decision['automation_status'] . ' ' . ($session->highlights[0]['summary'] ?? '') . PHP_EOL;
```

### Sealed token verification

```php
<?php

use Foil\Server\SealedToken;

$result = SealedToken::safeVerify($sealedToken, getenv('FOIL_SECRET_KEY') ?: null);

if (!$result->ok) {
    error_log($result->error?->getMessage() ?? 'Foil verification failed.');
    return;
}

echo $result->data?->decision['verdict'] . ' ' . $result->data?->decision['risk_score'];
```

### Pagination

```php
<?php

foreach ($client->sessions()->iterate(search: 'signup') as $session) {
    echo $session->id . ' ' . $session->latest_decision['verdict'] . PHP_EOL;
}
```

### Visitor fingerprints

```php
<?php

$fingerprint = $client->fingerprints()->get('vid_0123456789abcdefghjkmnpqrs');
echo $fingerprint->id;
```

### Organizations

```php
<?php

$organization = $client->organizations()->get('org_0123456789abcdefghjkmnpqrs');
$updated = $client->organizations()->update('org_0123456789abcdefghjkmnpqrs', name: 'New Name');

echo $updated->name;
```

### Organization API keys

```php
<?php

$created = $client->organizations()->apiKeys()->create('org_0123456789abcdefghjkmnpqrs', name: 'Production', type: 'secret', environment: 'live');
$client->organizations()->apiKeys()->revoke('org_0123456789abcdefghjkmnpqrs', $created->id);
```

### Webhooks

```php
<?php

$endpoint = $client->webhooks()->createEndpoint(
    'org_0123456789abcdefghjkmnpqrs',
    'Production alerts',
    'https://example.com/foil/webhook',
    ['session.result.persisted', 'gate.session.approved'],
);

$events = $client->webhooks()->listEvents(
    'org_0123456789abcdefghjkmnpqrs',
    endpointId: $endpoint->id,
    type: 'session.result.persisted',
);

echo $events->items[0]->webhook_deliveries[0]->status;
```

### Gate APIs

```php
<?php

use Foil\Server\Client;
use Foil\Server\GateDelivery;

$client = new Client();
$services = $client->gate()->registry()->list();
$session = $client->gate()->sessions()->create(
    serviceId: 'foil',
    accountName: 'my-project',
    delivery: GateDelivery::createDeliveryKeyPair()['delivery'],
);

echo $services[0]->id . ' ' . $session->consent_url . PHP_EOL;
```

### Gate delivery and webhook helpers

```php
<?php

use Foil\Server\GateDelivery;

$keyPair = GateDelivery::createDeliveryKeyPair();
$response = GateDelivery::createGateApprovedWebhookResponse([
    'delivery' => $keyPair['delivery'],
    'outputs' => [
        'FOIL_PUBLISHABLE_KEY' => 'pk_live_...',
        'FOIL_SECRET_KEY' => 'sk_live_...',
    ],
]);
$payload = GateDelivery::decryptGateDeliveryEnvelope($keyPair['private_key'], $response['encrypted_delivery']);

echo $payload['outputs']['FOIL_SECRET_KEY'] . PHP_EOL;
```

### Error handling

```php
<?php

use Foil\Server\Exception\FoilApiError;

try {
    $client->sessions()->list(limit: 999);
} catch (FoilApiError $error) {
    error_log($error->status . ' ' . $error->code . ' ' . $error->getMessage());
}
```

## Support

If you need help integrating Foil, start with [usefoil.com/docs](https://usefoil.com/docs).
