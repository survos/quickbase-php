<?php

declare(strict_types=1);

namespace Survos\Quickbase\Client;

use Survos\Quickbase\Contract\QuickbaseClientInterface;
use Survos\Quickbase\Exception\QuickbaseApiException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class QuickbaseClient implements QuickbaseClientInterface
{
    public function __construct(private HttpClientInterface $http)
    {
    }

    public function createApp(array $definition): array
    {
        self::requireDefinitionString($definition, 'name', 'app');

        return self::object($this->request('POST', 'apps', ['json' => $definition]));
    }

    public function app(string $appId): array
    {
        self::requireId($appId, 'app');

        return self::object($this->request('GET', sprintf('apps/%s', rawurlencode($appId))));
    }

    public function updateApp(string $appId, array $definition): array
    {
        self::requireId($appId, 'app');

        return self::object($this->request('POST', sprintf('apps/%s', rawurlencode($appId)), ['json' => $definition]));
    }

    public function deleteApp(string $appId, string $appName): array
    {
        self::requireId($appId, 'app');
        if ('' === trim($appName)) {
            throw new \InvalidArgumentException('The Quickbase app name confirmation cannot be empty.');
        }

        return self::object($this->request('DELETE', sprintf('apps/%s', rawurlencode($appId)), [
            'json' => ['name' => $appName],
        ]));
    }

    public function tables(string $appId): array
    {
        if ('' === trim($appId)) {
            throw new \InvalidArgumentException('The Quickbase app ID cannot be empty.');
        }

        return self::objectList($this->request('GET', 'tables', [
            'query' => ['appId' => $appId],
        ]));
    }

    public function fields(string $tableId, bool $includeFieldPermissions = false): array
    {
        if ('' === trim($tableId)) {
            throw new \InvalidArgumentException('The Quickbase table ID cannot be empty.');
        }

        $query = ['tableId' => $tableId];
        if ($includeFieldPermissions) {
            // Quickbase documents this query value as the literal string "true";
            // HttpClient's default boolean encoding (1/0) is rejected with HTTP 400.
            $query['includeFieldPerms'] = 'true';
        }

        return self::objectList($this->request('GET', 'fields', ['query' => $query]));
    }

    public function relationships(string $tableId, int $skip = 0): array
    {
        self::requireId($tableId, 'table');
        if ($skip < 0) {
            throw new \InvalidArgumentException('The Quickbase relationship skip cannot be negative.');
        }

        $result = $this->request('GET', sprintf('tables/%s/relationships', rawurlencode($tableId)), [
            'query' => ['skip' => $skip],
        ]);
        $metadata = $result['metadata'] ?? null;
        $relationships = $result['relationships'] ?? null;
        if (!is_array($metadata) || !is_array($relationships)) {
            throw new \UnexpectedValueException('Quickbase returned an invalid relationships response.');
        }

        return [
            'metadata' => self::object($metadata),
            'relationships' => self::objectList($relationships),
        ];
    }

    public function reports(string $tableId): array
    {
        self::requireId($tableId, 'table');

        return self::objectList($this->request('GET', 'reports', [
            'query' => ['tableId' => $tableId],
        ]));
    }

    public function queryRecords(
        string $tableId,
        array $select = [],
        ?string $where = null,
        array $sortBy = [],
        int $skip = 0,
        int $top = 100,
    ): array {
        self::requireId($tableId, 'table');
        if ($skip < 0) {
            throw new \InvalidArgumentException('The Quickbase record skip cannot be negative.');
        }
        if ($top < 1) {
            throw new \InvalidArgumentException('The Quickbase record limit must be at least 1.');
        }

        $payload = [
            'from' => $tableId,
            'options' => ['skip' => $skip, 'top' => $top],
        ];
        if ([] !== $select) {
            $payload['select'] = self::positiveFieldIds($select);
        }
        if (null !== $where && '' !== trim($where)) {
            $payload['where'] = $where;
        }
        if ([] !== $sortBy) {
            foreach ($sortBy as $sort) {
                if ($sort['fieldId'] < 1 || !in_array($sort['order'], ['ASC', 'DESC'], true)) {
                    throw new \InvalidArgumentException('Each Quickbase sort must contain a positive fieldId and ASC or DESC order.');
                }
            }
            $payload['sortBy'] = $sortBy;
        }

        return self::object($this->request('POST', 'records/query', ['json' => $payload]));
    }

    public function createTable(string $appId, array $definition): array
    {
        self::requireId($appId, 'app');
        self::requireDefinitionString($definition, 'name', 'table');
        self::requireDefinitionString($definition, 'singleRecordName', 'table');

        return self::object($this->request('POST', 'tables', [
            'query' => ['appId' => $appId],
            'json' => $definition,
        ]));
    }

    public function updateTable(string $appId, string $tableId, array $definition): array
    {
        self::requireId($appId, 'app');
        self::requireId($tableId, 'table');

        return self::object($this->request('POST', sprintf('tables/%s', rawurlencode($tableId)), [
            'query' => ['appId' => $appId],
            'json' => $definition,
        ]));
    }

    public function deleteTable(string $appId, string $tableId): array
    {
        self::requireId($appId, 'app');
        self::requireId($tableId, 'table');

        return self::object($this->request('DELETE', sprintf('tables/%s', rawurlencode($tableId)), [
            'query' => ['appId' => $appId],
        ]));
    }

    public function createField(string $tableId, array $definition): array
    {
        self::requireId($tableId, 'table');
        self::requireDefinitionString($definition, 'label', 'field');
        self::requireDefinitionString($definition, 'fieldType', 'field');

        return self::object($this->request('POST', 'fields', [
            'query' => ['tableId' => $tableId],
            'json' => $definition,
        ]));
    }

    public function updateField(string $tableId, int $fieldId, array $definition): array
    {
        self::requireId($tableId, 'table');
        self::requirePositiveInt($fieldId, 'field');

        return self::object($this->request('POST', sprintf('fields/%d', $fieldId), [
            'query' => ['tableId' => $tableId],
            'json' => $definition,
        ]));
    }

    public function deleteFields(string $tableId, array $fieldIds): array
    {
        self::requireId($tableId, 'table');

        return self::object($this->request('DELETE', 'fields', [
            'query' => ['tableId' => $tableId],
            'json' => ['fieldIds' => self::positiveFieldIds($fieldIds)],
        ]));
    }

    public function createRelationship(string $childTableId, array $definition): array
    {
        self::requireId($childTableId, 'child table');
        self::requireDefinitionString($definition, 'parentTableId', 'relationship');

        return self::object($this->request('POST', sprintf('tables/%s/relationship', rawurlencode($childTableId)), [
            'json' => $definition,
        ]));
    }

    public function updateRelationship(string $childTableId, int $relationshipId, array $definition): array
    {
        self::requireId($childTableId, 'child table');
        self::requirePositiveInt($relationshipId, 'relationship');

        return self::object($this->request('POST', sprintf('tables/%s/relationship/%d', rawurlencode($childTableId), $relationshipId), [
            'json' => $definition,
        ]));
    }

    public function deleteRelationship(string $childTableId, int $relationshipId): array
    {
        self::requireId($childTableId, 'child table');
        self::requirePositiveInt($relationshipId, 'relationship');

        return self::object($this->request('DELETE', sprintf('tables/%s/relationship/%d', rawurlencode($childTableId), $relationshipId)));
    }

    public function upsertRecords(string $tableId, iterable $records, ?int $mergeFieldId = null, array $fieldsToReturn = []): array
    {
        if ('' === trim($tableId)) {
            throw new \InvalidArgumentException('The Quickbase table ID cannot be empty.');
        }

        $data = [];
        foreach ($records as $record) {
            $data[] = self::normalizeRecord($record);
        }
        if ([] === $data) {
            throw new \InvalidArgumentException('At least one Quickbase record is required.');
        }

        $payload = ['to' => $tableId, 'data' => $data];
        if (null !== $mergeFieldId) {
            $payload['mergeFieldId'] = $mergeFieldId;
        }
        if ([] !== $fieldsToReturn) {
            $payload['fieldsToReturn'] = $fieldsToReturn;
        }

        return self::object($this->request('POST', 'records', ['json' => $payload]));
    }

    public function deleteRecords(string $tableId, string $where): array
    {
        self::requireId($tableId, 'table');
        if ('' === trim($where)) {
            throw new \InvalidArgumentException('The Quickbase delete-records query cannot be empty.');
        }

        return self::object($this->request('DELETE', 'records', ['json' => ['from' => $tableId, 'where' => $where]]));
    }

    public function exportSolution(string $solutionId, string $qblVersion = '0.14'): string
    {
        self::requireId($solutionId, 'solution');

        return $this->requestRaw('GET', sprintf('solutions/%s', rawurlencode($solutionId)), [
            'headers' => ['QBL-Version' => $qblVersion, 'Accept' => 'application/yaml'],
        ]);
    }

    public function createSolution(string $qbl, string $qblVersion = '0.14'): array
    {
        return self::object($this->request('POST', 'solutions', self::qblOptions($qbl, $qblVersion)));
    }

    public function updateSolution(string $solutionId, string $qbl, string $qblVersion = '0.14'): array
    {
        self::requireId($solutionId, 'solution');

        return self::object($this->request('POST', sprintf('solutions/%s', rawurlencode($solutionId)), self::qblOptions($qbl, $qblVersion)));
    }

    public function solutionChanges(string $solutionId, string $qbl, string $qblVersion = '0.14'): array
    {
        self::requireId($solutionId, 'solution');

        return self::object($this->request('POST', sprintf('solutions/%s/changes', rawurlencode($solutionId)), self::qblOptions($qbl, $qblVersion)));
    }

    /** @return array<array-key, mixed> */
    /**
     * A single table's definition. `tables()` lists them; this fetches one.
     */
    public function table(string $tableId, string $appId): array
    {
        self::requireId($tableId, 'table');
        self::requireId($appId, 'app');

        return $this->request('GET', 'tables/'.rawurlencode($tableId), ['query' => ['appId' => $appId]]);
    }

    /**
     * A single field's definition. `fields()` lists them; this fetches one.
     */
    public function field(int $fieldId, string $tableId): array
    {
        self::requireId($tableId, 'table');

        return $this->request('GET', 'fields/'.$fieldId, ['query' => ['tableId' => $tableId]]);
    }

    /**
     * Where a field is used -- reports, formulas, relationships. Worth checking before
     * deleting one.
     */
    public function fieldUsage(int $fieldId, string $tableId): array
    {
        self::requireId($tableId, 'table');

        return $this->request('GET', 'fields/usage/'.$fieldId, ['query' => ['tableId' => $tableId]]);
    }

    /**
     * Records changed since a point in time -- the endpoint an incremental sync wants,
     * rather than re-querying everything.
     */
    public function recordsModifiedSince(string $tableId, \DateTimeInterface $since, array $payload = []): array
    {
        self::requireId($tableId, 'table');

        return $this->request('POST', 'records/modifiedSince', [
            'json' => $payload + ['tableId' => $tableId, 'since' => $since->format(\DateTimeInterface::ATOM)],
        ]);
    }

    /**
     * A single report's definition. `reports()` lists them.
     */
    public function report(string $reportId, string $tableId): array
    {
        self::requireId($reportId, 'report');
        self::requireId($tableId, 'table');

        return $this->request('GET', 'reports/'.rawurlencode($reportId), ['query' => ['tableId' => $tableId]]);
    }

    /**
     * Run a saved report. Lets an application reuse filters and column selections that
     * were defined in Quickbase rather than restating them in code.
     */
    public function runReport(string $reportId, string $tableId, int $skip = 0, ?int $top = null): array
    {
        self::requireId($reportId, 'report');
        self::requireId($tableId, 'table');

        $query = ['tableId' => $tableId, 'skip' => $skip];
        if (null !== $top) {
            $query['top'] = $top;
        }

        return $this->request('POST', 'reports/'.rawurlencode($reportId).'/run', ['query' => $query]);
    }

    /**
     * Evaluate a Quickbase formula against one record, without storing it.
     */
    public function runFormula(string $formula, string $tableId, int|string $recordId): array
    {
        self::requireId($tableId, 'table');

        return $this->request('POST', 'formula/run', [
            'json' => ['formula' => $formula, 'rid' => $recordId, 'from' => $tableId],
        ]);
    }

    /**
     * Download a file attachment. Returns raw bytes, not JSON -- Quickbase base64-encodes
     * the body, so it is decoded here.
     */
    public function downloadFile(string $tableId, int|string $recordId, int $fieldId, int $versionNumber = 0): string
    {
        self::requireId($tableId, 'table');

        $raw = $this->requestRaw('GET', sprintf(
            'files/%s/%s/%d/%d',
            rawurlencode($tableId), rawurlencode((string) $recordId), $fieldId, $versionNumber
        ));

        return base64_decode(trim($raw, '"'), true) ?: $raw;
    }

    /**
     * Delete one version of a file attachment.
     */
    public function deleteFile(string $tableId, int|string $recordId, int $fieldId, int $versionNumber): array
    {
        self::requireId($tableId, 'table');

        return $this->request('DELETE', sprintf(
            'files/%s/%s/%d/%d',
            rawurlencode($tableId), rawurlencode((string) $recordId), $fieldId, $versionNumber
        ));
    }

    /**
     * A short-lived token scoped to one app or table, safe to hand to a browser -- unlike
     * the user token, which must stay on the server.
     */
    public function temporaryToken(string $dbid): array
    {
        self::requireId($dbid, 'app');

        return $this->request('GET', 'auth/temporary/'.rawurlencode($dbid));
    }

    /**
     * An app's event subscriptions -- webhooks, notifications, subscriptions, reminders.
     */
    public function appEvents(string $appId): array
    {
        self::requireId($appId, 'app');

        return self::objectList($this->request('GET', 'apps/'.rawurlencode($appId).'/events'));
    }

    /**
     * Copy an app, with or without its data.
     */
    public function copyApp(string $appId, array $definition): array
    {
        self::requireId($appId, 'app');

        return $this->request('POST', 'apps/'.rawurlencode($appId).'/copy', ['json' => $definition]);
    }

    public function request(string $method, string $path, array $options = []): array
    {
        $content = $this->requestRaw($method, $path, $options);
        if ('' === $content) {
            return [];
        }

        try {
            $value = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new QuickbaseApiException('Quickbase returned a non-JSON success response.', 200, previous: $exception);
        }

        return is_array($value) ? $value : ['data' => $value];
    }

    public function requestRaw(string $method, string $path, array $options = []): string
    {
        try {
            $response = $this->http->request($method, ltrim($path, '/'), $options);
            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            throw new QuickbaseApiException('Quickbase transport failure: '.$exception->getMessage(), 0, previous: $exception);
        }

        $decoded = [];
        if ('' !== $content) {
            try {
                $value = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($value)) {
                    $decoded = ['data' => $value];
                } else {
                    $decoded = $value;
                }
            } catch (\JsonException) {
                $decoded = [];
            }
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            // Quickbase puts a generic label in `message` ("Invalid input") and the reason that
            // actually identifies the problem in `description`. Keep both.
            $message = self::errorMessage($decoded) ?? self::nonJsonError($content, $statusCode);
            throw new QuickbaseApiException(is_string($message) ? $message : sprintf('Quickbase request failed with HTTP %d.', $statusCode), $statusCode, self::firstHeader($headers, 'qb-api-ray'), $decoded);
        }

        return $content;
    }

    /** @return array<string, mixed> */
    private static function qblOptions(string $qbl, string $qblVersion): array
    {
        if ('' === trim($qbl)) {
            throw new \InvalidArgumentException('The QBL document cannot be empty.');
        }

        return [
            'headers' => ['QBL-Version' => $qblVersion, 'Content-Type' => 'application/yaml', 'Accept' => 'application/json'],
            'body' => $qbl,
        ];
    }

    /** @param array<array-key, mixed> $decoded */
    private static function errorMessage(array $decoded): ?string
    {
        $parts = [];
        foreach (['message', 'description'] as $key) {
            $value = $decoded[$key] ?? null;
            if (is_string($value) && '' !== trim($value) && !in_array($value, $parts, true)) {
                $parts[] = $value;
            }
        }

        return [] === $parts ? null : implode(': ', $parts);
    }

    private static function nonJsonError(string $content, int $statusCode): string
    {
        $plain = trim(strip_tags($content));
        $plain = preg_replace('/\s+/', ' ', $plain) ?? '';

        return '' === $plain
            ? sprintf('Quickbase request failed with HTTP %d.', $statusCode)
            : sprintf('Quickbase request failed with HTTP %d: %s', $statusCode, mb_strimwidth($plain, 0, 300, '…'));
    }

    /**
     * @param array<int|string, mixed> $record
     *
     * @return array<int, mixed>
     */
    private static function normalizeRecord(array $record): array
    {
        $normalized = [];
        foreach ($record as $fieldId => $value) {
            if ((!is_int($fieldId) && !ctype_digit((string) $fieldId)) || (int) $fieldId < 1) {
                throw new \InvalidArgumentException(sprintf('Quickbase field ID "%s" must be numeric.', $fieldId));
            }
            $normalized[(int) $fieldId] = is_array($value) && array_key_exists('value', $value) ? $value : ['value' => $value];
        }

        return $normalized;
    }

    /**
     * @param list<int> $fieldIds
     *
     * @return list<int>
     */
    private static function positiveFieldIds(array $fieldIds): array
    {
        foreach ($fieldIds as $fieldId) {
            if ($fieldId < 1) {
                throw new \InvalidArgumentException('Quickbase field IDs must be positive integers.');
            }
        }

        return $fieldIds;
    }

    private static function requireId(string $id, string $kind): void
    {
        if ('' === trim($id)) {
            throw new \InvalidArgumentException(sprintf('The Quickbase %s ID cannot be empty.', $kind));
        }
    }

    private static function requirePositiveInt(int $id, string $kind): void
    {
        if ($id < 1) {
            throw new \InvalidArgumentException(sprintf('The Quickbase %s ID must be positive.', $kind));
        }
    }

    /** @param array<string, mixed> $definition */
    private static function requireDefinitionString(array $definition, string $key, string $kind): void
    {
        $value = $definition[$key] ?? null;
        if (!is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException(sprintf('The Quickbase %s definition requires a non-empty "%s".', $kind, $key));
        }
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return list<array<string, mixed>>
     */
    private static function objectList(array $value): array
    {
        $objects = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \UnexpectedValueException('Quickbase returned a non-object item in a metadata list.');
            }

            $object = [];
            foreach ($item as $key => $fieldValue) {
                if (!is_string($key)) {
                    throw new \UnexpectedValueException('Quickbase returned a metadata object with a non-string key.');
                }
                $object[$key] = $fieldValue;
            }
            $objects[] = $object;
        }

        return $objects;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function object(array $value): array
    {
        $object = [];
        foreach ($value as $key => $fieldValue) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Quickbase returned an object with a non-string key.');
            }
            $object[$key] = $fieldValue;
        }

        return $object;
    }

    /** @param array<string, list<string>> $headers */
    private static function firstHeader(array $headers, string $name): ?string
    {
        return ($headers[strtolower($name)] ?? [])[0] ?? null;
    }
}
