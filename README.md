# survos/quickbase-php

A PHP client for the [Quickbase](https://www.quickbase.com/) **JSON REST API** — the API
client, a [record-store](https://github.com/survos/record-store) adapter, schema
management, and QBL solution support.

Framework-agnostic. It depends on `symfony/http-client-contracts`, an **interface**
package, so any implementation works. The same code runs in a Symfony application, a
WordPress plugin, or a plain script.

Other Quickbase packages exist on Packagist; most target the older XML API. This one is
built for the JSON REST API and for treating an application's schema as something you
version rather than click.

```bash
composer require survos/quickbase-php symfony/http-client
```

## Authentication

Quickbase authenticates with a realm hostname and a user token, both sent as headers.
Configure them once on the HTTP client:

```php
use Symfony\Component\HttpClient\HttpClient;
use Survos\Quickbase\Client\QuickbaseClient;

$client = new QuickbaseClient(HttpClient::create()->withOptions([
    'base_uri' => 'https://api.quickbase.com/v1/',
    'headers'  => [
        'QB-Realm-Hostname' => $_ENV['QUICKBASE_REALM'],
        'Authorization'     => 'QB-USER-TOKEN '.$_ENV['QUICKBASE_API_KEY'],
    ],
]));
```

## Reading and writing records

```php
use Survos\Quickbase\Adapter\QuickbaseAdapterFactory;
use Survos\RecordStore\Model\{ConnectionConfiguration, Record, RecordQuery, TableReference, UpsertRequest};

$adapter = (new QuickbaseAdapterFactory($client))
    ->create(new ConnectionConfiguration(name: 'rappathome', driver: 'quickbase'));

$members = new TableReference(
    application:   'rappathome',
    applicationId: $appId,
    connection:    'rappathome',
    name:          'members',
    id:            $tableId,
    fields:        ['bio' => 12],   // logical name => Quickbase field id
);

$page = $adapter->query($members, new RecordQuery(limit: 20));

$adapter->upsert($members, new UpsertRequest(
    records:   [new Record(fields: ['bio' => 'Updated bio'], id: 314)],
    keyFields: ['id'],
));
```

Because `TableReference` carries a logical-name to field-id map, application code says
`bio` rather than `12`. Quickbase's numeric field ids stay at the edge.

**Writes use the ordinary records API**, so Pipelines and Automations that listen on
record events fire the same way they do for a change made in the Quickbase UI.

## Schema and QBL

`Schema\QuickbaseSchemaManager` and `Qbl\QblDocument` cover the parts of Quickbase that
are about structure rather than rows. The client wraps the full surface: apps, tables,
fields, relationships, reports, and solution export/create/update/diff in QBL —
`exportSolution()`, `createSolution()`, `updateSolution()`, `solutionChanges()`.

`QuickbaseAppRegistry` resolves friendly names to app ids, builds deep links with
`appUrl()` and `tableUrl()`, and can mark an app **read-only** so a misconfigured
environment cannot write to production.

## Provider neutrality

The adapter implements `RecordStoreAdapterInterface`, the same contract
[`survos/grist-php`](https://github.com/survos/grist-php) implements. Code written against
that interface runs on either backend, and the choice becomes configuration. Anything
Quickbase cannot do raises `UnsupportedRecordStoreOperation` rather than being quietly
approximated.

## Symfony

[`survos/quickbase-bundle`](https://github.com/survos/quickbase-bundle) provides the
Symfony integration — configuration, service wiring, console commands. It contains no
Quickbase logic of its own.
