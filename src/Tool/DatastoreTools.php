<?php

namespace Drupal\dkan_query_tools\Tool;

use Drupal\common\DatasetInfo;
use Drupal\Core\Database\Connection;
use Drupal\datastore\DatastoreService;
use Drupal\datastore\Service\DatastoreQuery;
use Drupal\datastore\Service\Query;
use Drupal\metastore\MetastoreService;
use Psr\Log\LoggerInterface;

/**
 * Tools for DKAN datastore operations.
 */
class DatastoreTools {

  protected const MAX_DATASETS = 200;

  /**
   * Per-instance memo of resourceId → dictionary lookup result.
   *
   * Values: array with [identifier, url, fields] on a hit; FALSE for a
   * looked-up-but-no-link miss. Avoids walking the dataset list twice in a
   * single tool call sequence.
   */
  protected array $dictionaryCache = [];

  /**
   * Per-instance memo of resourceId → schema column-name list.
   *
   * canonicalizeColumnNames() can fire several times per query (one per
   * input axis: columns, groupings, sort, conditions); the memo keeps the
   * cost to a single getStorage()/getSchema() per resource per request.
   *
   * @var array<string, string[]>
   */
  protected array $schemaColumnsCache = [];

  /**
   * Service-level toggle for dictionary enrichment.
   *
   * Defaults to TRUE (production). Used by the eval harness to compare
   * with-vs-without the enrichment without a code revert. Per-call
   * `$includeDictionary` still wins when FALSE; this flag only matters when
   * the per-call flag is TRUE.
   */
  protected bool $dictionaryEnrichmentEnabled = TRUE;

  public function __construct(
    protected DatastoreService $datastoreService,
    protected Query $queryService,
    protected MetastoreService $metastore,
    protected DatasetInfo $datasetInfo,
    protected Connection $database,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Toggle dictionary enrichment for the lifetime of this service instance.
   *
   * Intended for the eval harness — production callers should leave this at
   * its TRUE default. Pass FALSE to make `getDatastoreSchema()` skip the
   * lookup even when callers don't pass `includeDictionary: false`.
   */
  public function setDictionaryEnrichmentEnabled(bool $enabled): void {
    $this->dictionaryEnrichmentEnabled = $enabled;
    if (!$enabled) {
      $this->dictionaryCache = [];
    }
  }

  /**
   * Query a datastore resource with filters, sorting, pagination, and aggregation.
   */
  public function queryDatastore(
    string $resourceId,
    ?string $columns = NULL,
    ?string $conditions = NULL,
    ?string $sortField = NULL,
    string $sortDirection = 'asc',
    int $limit = 100,
    int $offset = 0,
    ?string $expressions = NULL,
    ?string $groupings = NULL,
    int $maxLimit = 500,
  ): array {
    $limit = min(max($limit, 1), max(1, $maxLimit));

    $query = [
      'resources' => [['id' => $resourceId, 'alias' => 't']],
      'limit' => $limit,
      'offset' => $offset,
      'count' => TRUE,
      'results' => TRUE,
      'keys' => TRUE,
    ];

    $properties = [];
    if ($columns) {
      $properties = array_map('trim', explode(',', $columns));
      $properties = $this->canonicalizeColumnNames($resourceId, $properties);
    }

    $groupList = $groupings ? array_map('trim', explode(',', $groupings)) : [];
    if ($groupList) {
      $groupList = $this->canonicalizeColumnNames($resourceId, $groupList);
    }

    if ($expressions) {
      $schemaColumns = $this->getSchemaColumnNames($resourceId);
      $reservedNames = array_unique(array_merge($properties, $groupList, $schemaColumns));
      $exprResult = $this->validateAndBuildExpressions($expressions, $reservedNames);
      if (isset($exprResult['error'])) {
        return $exprResult;
      }
      array_push($properties, ...$exprResult['expressions']);
    }

    if ($groupList) {
      $query['groupings'] = array_map(
        fn(string $col) => ['property' => $col],
        $groupList,
      );
      // Auto-include grouped columns in properties so they appear in results.
      $toAdd = [];
      foreach ($groupList as $col) {
        if (!in_array($col, $properties, TRUE)) {
          $toAdd[] = $col;
        }
      }
      if ($toAdd) {
        array_unshift($properties, ...$toAdd);
      }
    }

    if ($properties) {
      $query['properties'] = $properties;
    }

    if ($conditions) {
      $parsed = json_decode($conditions, TRUE);
      if (!is_array($parsed) || !array_is_list($parsed)) {
        return ['error' => 'Invalid conditions: must be a JSON array of condition objects, e.g. [{"property":"col","value":"val","operator":"="}]'];
      }
      $parsed = $this->canonicalizeConditionProperties($parsed, $resourceId);
      $query['conditions'] = self::canonicalizeOperators($parsed);
    }

    if ($sortField) {
      $sortFields = $this->canonicalizeColumnNames($resourceId, [$sortField]);
      $query['sorts'] = [
        [
          'property' => $sortFields[0],
          'order' => strtolower($sortDirection) === 'desc' ? 'desc' : 'asc',
        ],
      ];
    }

    try {
      $datastoreQuery = new DatastoreQuery(
        json_encode($query),
        $limit,
      );
      $result = $this->queryService->runQuery($datastoreQuery);
      $decoded = json_decode((string) $result, TRUE);

      return $this->buildSuccessResponse(
        $decoded['results'] ?? [],
        (int) ($decoded['count'] ?? 0),
        $limit,
        $offset,
        $resourceId,
        $query['conditions'] ?? NULL,
      );
    }
    catch (\Exception $e) {
      $this->logger->error('MCP: Datastore query failed for @id: @error', ['@id' => $resourceId, '@error' => $e->getMessage()]);
      return $this->buildErrorResponse($e, $resourceId);
    }
  }

  /**
   * Return the first N rows of a datastore resource in stable order.
   *
   * Useful for orienting an LLM agent to actual cell shapes, code values, and
   * units before it composes filters or aggregations. Sorted by record_number
   * ascending so repeated calls return the same rows.
   *
   * @param string $resourceId
   *   Resource id in identifier__version form.
   * @param int $n
   *   Number of rows to return. Clamped to [1, 50].
   *
   * @return array
   *   ['resource_id', 'rows', 'row_count'] or ['error' => message].
   */
  public function sampleRows(string $resourceId, int $n = 5): array {
    $n = min(max($n, 1), 50);
    try {
      [$identifier, $version] = $this->parseResourceId($resourceId);
      $storage = $this->datastoreService->getStorage($identifier, $version);
      $tableName = $storage->getTableName();
      $rows = $this->database->select($tableName, 't')
        ->fields('t')
        ->orderBy('record_number', 'ASC')
        ->range(0, $n)
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      // Strip record_number from each row — it's a synthetic column.
      $rows = array_map(static function (array $row): array {
        unset($row['record_number']);
        return $row;
      }, $rows);

      return [
        'resource_id' => $resourceId,
        'rows' => $rows,
        'row_count' => count($rows),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('MCP: Sample rows failed for @id: @error', [
        '@id' => $resourceId,
        '@error' => $e->getMessage(),
      ]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Return distinct values of a column for a datastore resource.
   *
   * Helps an LLM agent learn the code list / enum domain of a column before
   * filtering. Returns at most $limit values; sets truncated=true when more
   * exist.
   *
   * @param string $resourceId
   *   Resource id in identifier__version form.
   * @param string $column
   *   Column name to enumerate.
   * @param int $limit
   *   Maximum values to return. Clamped to [1, 500].
   *
   * @return array
   *   ['resource_id', 'column', 'values', 'value_count', 'truncated'] or
   *   ['error' => message].
   */
  public function distinctValues(string $resourceId, string $column, int $limit = 50): array {
    $limit = min(max($limit, 1), 500);
    try {
      [$identifier, $version] = $this->parseResourceId($resourceId);
      $storage = $this->datastoreService->getStorage($identifier, $version);
      $schema = $storage->getSchema();
      $canonical = $this->canonicalizeColumnNames($resourceId, [$column]);
      $column = $canonical[0];
      if (!isset($schema['fields'][$column]) || $column === 'record_number') {
        return ['error' => "Unknown column '{$column}' for resource '{$resourceId}'"];
      }
      $tableName = $storage->getTableName();

      // Fetch limit+1 to detect truncation.
      $query = $this->database->select($tableName, 't');
      $query->addField('t', $column, 'value');
      $query->distinct();
      $query->orderBy('value', 'ASC');
      $query->range(0, $limit + 1);
      $rows = $query->execute()->fetchCol();

      $truncated = count($rows) > $limit;
      $values = array_slice($rows, 0, $limit);
      // Drop NULLs but keep empty strings (they are real distinct values).
      $values = array_values(array_filter($values, static fn($v) => $v !== NULL));

      return [
        'resource_id' => $resourceId,
        'column' => $column,
        'values' => $values,
        'value_count' => count($values),
        'truncated' => $truncated,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('MCP: Distinct values failed for @id.@col: @error', [
        '@id' => $resourceId,
        '@col' => $column,
        '@error' => $e->getMessage(),
      ]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Get the schema (column names and types) for a datastore resource.
   *
   * When the linked distribution carries a `describedBy` data dictionary,
   * each column is enriched with `dictionary_title`, `dictionary_description`,
   * and `dictionary_type` (the publisher-declared type, distinct from the
   * DB-derived `type`). The response root gains `dictionary_identifier` and
   * `dictionary_url` when a dictionary is resolved.
   *
   * @param string $resourceId
   *   Resource id in identifier__version form.
   * @param bool $includeDictionary
   *   When FALSE, skip the dictionary lookup (test/perf opt-out).
   */
  public function getDatastoreSchema(string $resourceId, bool $includeDictionary = TRUE): array {
    try {
      [$identifier, $version] = $this->parseResourceId($resourceId);
      $storage = $this->datastoreService->getStorage($identifier, $version);
      $schema = $storage->getSchema();

      $dictionary = ($includeDictionary && $this->dictionaryEnrichmentEnabled)
        ? $this->findDictionaryFor($resourceId)
        : NULL;
      $fieldsByName = $dictionary['fields'] ?? [];

      $columns = [];
      if (isset($schema['fields'])) {
        foreach ($schema['fields'] as $name => $definition) {
          if ($name === 'record_number') {
            continue;
          }
          $col = [
            'name' => $name,
            'type' => $definition['type'] ?? 'unknown',
          ];
          if (!empty($definition['description'])) {
            $col['description'] = $definition['description'];
          }
          if (isset($fieldsByName[$name])) {
            $field = $fieldsByName[$name];
            if (!empty($field['title'])) {
              $col['dictionary_title'] = $field['title'];
            }
            if (!empty($field['description'])) {
              $col['dictionary_description'] = $field['description'];
            }
            if (!empty($field['type'])) {
              $col['dictionary_type'] = $field['type'];
            }
          }
          $columns[] = $col;
        }
      }

      $result = ['resource_id' => $resourceId, 'columns' => $columns];
      if ($dictionary) {
        $result['dictionary_identifier'] = $dictionary['identifier'];
        $result['dictionary_url'] = $dictionary['url'];
      }
      return $result;
    }
    catch (\Exception $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Locate the data dictionary linked to a resource via its distribution.
   *
   * Walks the dataset list, matches a distribution's %Ref:downloadURL against
   * the parsed resource_id, reads the inline `describedBy` URL, extracts the
   * dictionary identifier from the URL's last path segment, and fetches the
   * dictionary item from the metastore.
   *
   * Best-effort: any failure (no link, bad URL, fetch error) returns NULL so
   * schema enrichment never breaks the primary call. Per-instance memoized.
   *
   * Known limitation: only finds dictionaries linked on inline distributions
   * (the DCAT-flat shape DKAN currently stores). Dictionaries linked via
   * standalone `distribution` metastore items would require a separate walk.
   *
   * @return array|null
   *   ['identifier' => string, 'url' => string, 'fields' => [name => array]]
   *   or NULL when no dictionary is linked or lookup failed.
   */
  protected function findDictionaryFor(string $resourceId): ?array {
    if (array_key_exists($resourceId, $this->dictionaryCache)) {
      return $this->dictionaryCache[$resourceId] ?: NULL;
    }
    $this->dictionaryCache[$resourceId] = FALSE;

    [$wantId, $wantVersion] = $this->parseResourceId($resourceId);
    if (!$wantId || !$wantVersion) {
      return NULL;
    }

    try {
      $datasets = $this->metastore->getAll('dataset', 0, self::MAX_DATASETS);
    }
    catch (\Throwable $e) {
      $this->logger->debug('Dictionary lookup: dataset list fetch failed for @id: @msg', [
        '@id' => $resourceId,
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }

    $describedBy = NULL;
    $matchedDistribution = FALSE;
    foreach ($datasets as $dataset) {
      $data = json_decode((string) $dataset);
      if (!isset($data->distribution) || !is_array($data->distribution)) {
        continue;
      }
      foreach ($data->distribution as $dist) {
        $ref = $dist->{'%Ref:downloadURL'}[0]->data ?? NULL;
        if (!$ref || ($ref->identifier ?? NULL) !== $wantId || ($ref->version ?? NULL) !== $wantVersion) {
          continue;
        }
        $matchedDistribution = TRUE;
        if (!empty($dist->describedBy)) {
          $describedBy = (string) $dist->describedBy;
        }
        break 2;
      }
    }
    if ($describedBy === NULL) {
      // Cap-hit warning: walk exhausted without finding the resource. If the
      // catalog has more than MAX_DATASETS entries, the linked distribution
      // may exist on a dataset we never inspected.
      if (!$matchedDistribution && count($datasets) >= self::MAX_DATASETS) {
        $this->logger->warning('Dictionary lookup hit dataset cap (@cap) without matching resource @id; consider raising the cap or adding a reverse-lookup index.', [
          '@cap' => self::MAX_DATASETS,
          '@id' => $resourceId,
        ]);
      }
      return NULL;
    }

    $dictId = $this->extractDictionaryIdentifier($describedBy);
    if ($dictId === NULL) {
      $this->logger->debug('Dictionary lookup: malformed describedBy URL for @id: @url', [
        '@id' => $resourceId,
        '@url' => $describedBy,
      ]);
      return NULL;
    }

    try {
      $doc = $this->metastore->get('data-dictionary', $dictId);
    }
    catch (\Throwable $e) {
      $this->logger->debug('Dictionary lookup: failed to fetch dictionary @dict for @id: @msg', [
        '@dict' => $dictId,
        '@id' => $resourceId,
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }

    $decoded = json_decode((string) $doc, TRUE);
    $rawFields = $decoded['data']['fields'] ?? [];
    if (!is_array($rawFields)) {
      return NULL;
    }
    $fieldsByName = [];
    foreach ($rawFields as $field) {
      if (!empty($field['name'])) {
        $fieldsByName[$field['name']] = $field;
      }
    }

    $payload = [
      'identifier' => $dictId,
      'url' => $describedBy,
      'fields' => $fieldsByName,
    ];
    $this->dictionaryCache[$resourceId] = $payload;
    return $payload;
  }

  /**
   * Extract the dictionary identifier from a `describedBy` URL.
   *
   * Expected shape:
   *   .../api/1/metastore/schemas/data-dictionary/items/<identifier>
   *
   * Returns the trailing path segment, or NULL if the URL is malformed.
   */
  protected function extractDictionaryIdentifier(string $url): ?string {
    $path = parse_url($url, PHP_URL_PATH);
    if (!$path) {
      return NULL;
    }
    $segments = array_values(array_filter(explode('/', $path), static fn($s) => $s !== ''));
    $id = end($segments);
    return $id !== FALSE && $id !== '' ? $id : NULL;
  }

  /**
   * Get import status for a datastore resource.
   */
  public function getImportStatus(string $resourceId): array {
    try {
      $summary = $this->datastoreService->summary($resourceId);
      $numOfRows = is_object($summary) ? ($summary->numOfRows ?? 0) : ($summary['numOfRows'] ?? 0);
      $numOfColumns = is_object($summary) ? ($summary->numOfColumns ?? 0) : ($summary['numOfColumns'] ?? 0);
      return [
        'resource_id' => $resourceId,
        'status' => $numOfRows > 0 ? 'done' : 'pending',
        'num_of_rows' => $numOfRows,
        'num_of_columns' => $numOfColumns,
      ];
    }
    catch (\Exception $e) {
      return ['resource_id' => $resourceId, 'status' => 'not_imported', 'error' => $e->getMessage()];
    }
  }

  /**
   * Join and query two datastore resources.
   */
  public function queryDatastoreJoin(
    string $resourceId,
    string $joinResourceId,
    string $joinOn,
    ?string $columns = NULL,
    ?string $conditions = NULL,
    ?string $sortField = NULL,
    string $sortDirection = 'asc',
    int $limit = 100,
    int $offset = 0,
    ?string $expressions = NULL,
    ?string $groupings = NULL,
    int $maxLimit = 500,
  ): array {
    $limit = min(max($limit, 1), max(1, $maxLimit));

    $query = [
      'resources' => [
        ['id' => $resourceId, 'alias' => 't'],
        ['id' => $joinResourceId, 'alias' => 'j'],
      ],
      'limit' => $limit,
      'offset' => $offset,
      'count' => TRUE,
      'results' => TRUE,
      'keys' => TRUE,
    ];

    // Parse join condition.
    $joinCondition = $this->parseJoinCondition($joinOn);
    if (isset($joinCondition['error'])) {
      return $joinCondition;
    }
    $query['joins'] = [$joinCondition];

    // Parse columns with resource qualification.
    $properties = [];
    if ($columns) {
      $properties = $this->parseQualifiedColumns($columns);
    }

    // Parse groupings with resource qualification.
    $groupList = $groupings ? array_map('trim', explode(',', $groupings)) : [];
    if ($groupList) {
      $query['groupings'] = array_map(
        fn(string $col) => $this->parseQualifiedField($col),
        $groupList,
      );
      // Auto-include grouped columns in properties as qualified objects.
      foreach ($groupList as $col) {
        $qualified = $this->parseQualifiedField($col);
        $alreadyIncluded = FALSE;
        foreach ($properties as $prop) {
          if (is_array($prop) && ($prop['resource'] ?? NULL) === $qualified['resource'] && ($prop['property'] ?? NULL) === $qualified['property']) {
            $alreadyIncluded = TRUE;
            break;
          }
        }
        if (!$alreadyIncluded) {
          array_unshift($properties, $qualified);
        }
      }
    }

    // Parse expressions.
    if ($expressions) {
      // For joins, use explicit columns and groupings as reserved names
      // (skip schema lookup — would need both resources' schemas).
      $reservedNames = $groupList;
      if ($columns) {
        $reservedNames = array_merge(
          array_map('trim', explode(',', $columns)),
          $reservedNames,
        );
      }
      $exprResult = $this->validateAndBuildExpressions($expressions, $reservedNames);
      if (isset($exprResult['error'])) {
        return $exprResult;
      }
      array_push($properties, ...$exprResult['expressions']);
    }

    if ($properties) {
      $query['properties'] = $properties;
    }

    // Parse conditions with optional resource field.
    if ($conditions) {
      $parsed = json_decode($conditions, TRUE);
      if (!is_array($parsed) || !array_is_list($parsed)) {
        return ['error' => 'Invalid conditions: must be a JSON array of condition objects.'];
      }
      $query['conditions'] = self::canonicalizeOperators($parsed);
    }

    // Parse sort with optional resource qualification.
    if ($sortField) {
      $sort = $this->parseQualifiedField($sortField);
      $sort['order'] = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';
      $query['sorts'] = [$sort];
    }

    try {
      $datastoreQuery = new DatastoreQuery(
        json_encode($query),
        $limit,
      );
      $result = $this->queryService->runQuery($datastoreQuery);
      $decoded = json_decode((string) $result, TRUE);

      return $this->buildSuccessResponse(
        $decoded['results'] ?? [],
        (int) ($decoded['count'] ?? 0),
        $limit,
        $offset,
        $resourceId,
        $query['conditions'] ?? NULL,
      );
    }
    catch (\Exception $e) {
      $this->logger->error('MCP: Datastore join query failed for @id: @error', ['@id' => $resourceId, '@error' => $e->getMessage()]);
      return $this->buildErrorResponse($e, $resourceId);
    }
  }

  /**
   * Parse a join condition from shorthand or JSON format.
   *
   * @return array
   *   DKAN join structure or ['error' => message].
   */
  protected function parseJoinCondition(string $joinOn): array {
    $trimmed = trim($joinOn);

    // JSON format: {"left":"t.col","right":"j.col","operator":"="}.
    if (str_starts_with($trimmed, '{')) {
      $parsed = json_decode($trimmed, TRUE);
      if (!is_array($parsed) || empty($parsed['left']) || empty($parsed['right'])) {
        return ['error' => 'Invalid JSON join_on: must have "left" and "right" fields (e.g., {"left":"t.col","right":"j.col","operator":"="}).'];
      }
      $left = $this->parseQualifiedField($parsed['left']);
      $right = $this->parseQualifiedField($parsed['right']);
      return [
        'resource' => $right['resource'] ?? 'j',
        'condition' => [
          'resource' => $left['resource'] ?? 't',
          'property' => $left['property'],
          'value' => $right,
        ],
      ];
    }

    // Simple format: "col1=col2".
    if (!str_contains($trimmed, '=')) {
      return ['error' => 'Invalid join_on: use "primary_col=join_col" or JSON format {"left":"t.col","right":"j.col","operator":"="}.'];
    }

    $parts = explode('=', $trimmed, 2);
    $leftCol = trim($parts[0]);
    $rightCol = trim($parts[1]);

    if ($leftCol === '' || $rightCol === '') {
      return ['error' => 'Invalid join_on: both sides of "=" must be non-empty.'];
    }

    // Parse qualified fields (e.g., "t.state=j.state") with defaults.
    $left = $this->parseQualifiedField($leftCol);
    if (!isset($left['resource']) || $left['resource'] === 't' && !str_contains($leftCol, '.')) {
      $left['resource'] = 't';
    }
    $right = $this->parseQualifiedField($rightCol);
    if (!isset($right['resource']) || $right['resource'] === 't' && !str_contains($rightCol, '.')) {
      $right['resource'] = 'j';
    }

    return [
      'resource' => $right['resource'],
      'condition' => [
        'resource' => $left['resource'],
        'property' => $left['property'],
        'value' => $right,
      ],
    ];
  }

  /**
   * Parse comma-separated columns with optional resource qualification.
   *
   * @return array
   *   Array of resource-qualified property objects.
   */
  protected function parseQualifiedColumns(string $columns): array {
    $result = [];
    foreach (array_map('trim', explode(',', $columns)) as $col) {
      $result[] = $this->parseQualifiedField($col);
    }
    return $result;
  }

  /**
   * Parse a single field with optional "alias.column" qualification.
   *
   * @return array
   *   Array with 'resource' and 'property' keys.
   */
  protected function parseQualifiedField(string $field): array {
    if (str_contains($field, '.')) {
      [$resource, $property] = explode('.', $field, 2);
      return ['resource' => $resource, 'property' => $property];
    }
    return ['resource' => 't', 'property' => $field];
  }

  /**
   * Search column names/descriptions across all imported datastore resources.
   */
  public function searchColumns(
    string $searchTerm,
    string $searchIn = 'name',
    int $limit = 100,
  ): array {
    $validSearchIn = ['name', 'description', 'both'];
    if (!in_array($searchIn, $validSearchIn, TRUE)) {
      return ['error' => 'Invalid search_in value "' . $searchIn . '". Valid values: ' . implode(', ', $validSearchIn)];
    }

    $searchTerm = strtolower(trim($searchTerm));
    if ($searchTerm === '') {
      return ['error' => 'search_term cannot be empty.'];
    }

    try {
      $matches = [];
      $resourcesSearched = 0;

      $datasetCount = $this->metastore->count('dataset');
      $sampled = $datasetCount > self::MAX_DATASETS;
      $datasets = $this->metastore->getAll('dataset', 0, self::MAX_DATASETS);

      foreach ($datasets as $dataset) {
        $data = json_decode((string) $dataset, TRUE);
        $uuid = $data['identifier'] ?? NULL;
        $title = $data['title'] ?? 'Unknown';
        if (!$uuid) {
          continue;
        }

        try {
          $info = $this->datasetInfo->gather($uuid);
        }
        catch (\Exception) {
          continue;
        }

        $distributions = $info['latest_revision']['distributions'] ?? [];
        foreach ($distributions as $dist) {
          if (($dist['importer_status'] ?? '') !== 'done') {
            continue;
          }

          $resourceId = $dist['resource_id'] ?? NULL;
          $version = $dist['resource_version'] ?? NULL;
          if (!$resourceId || !$version) {
            continue;
          }

          $fullResourceId = $resourceId . '__' . $version;

          try {
            $storage = $this->datastoreService->getStorage($resourceId, $version);
            $schema = $storage->getSchema();
          }
          catch (\Exception) {
            continue;
          }

          $resourcesSearched++;

          foreach ($schema['fields'] ?? [] as $name => $definition) {
            if ($name === 'record_number') {
              continue;
            }

            $nameMatch = str_contains(strtolower($name), $searchTerm);
            $descMatch = str_contains(strtolower($definition['description'] ?? ''), $searchTerm);

            $matched = match ($searchIn) {
              'name' => $nameMatch,
              'description' => $descMatch,
              'both' => $nameMatch || $descMatch,
            };

            if (!$matched) {
              continue;
            }

            $matchedIn = match ($searchIn) {
              'name' => 'name',
              'description' => 'description',
              'both' => match (TRUE) {
                $nameMatch && $descMatch => 'both',
                $nameMatch => 'name',
                default => 'description',
              },
            };

            $match = [
              'dataset_title' => $title,
              'dataset_uuid' => $uuid,
              'resource_id' => $fullResourceId,
              'column_name' => $name,
              'column_type' => $definition['type'] ?? 'unknown',
              'matched_in' => $matchedIn,
            ];
            if (!empty($definition['description'])) {
              $match['column_description'] = $definition['description'];
            }
            $matches[] = $match;

            if (count($matches) >= $limit) {
              break 3;
            }
          }
        }
      }

      $result = [
        'matches' => $matches,
        'total_matches' => count($matches),
        'resources_searched' => $resourcesSearched,
      ];
      if ($sampled) {
        $result['sampled'] = TRUE;
        $result['sample_size'] = self::MAX_DATASETS;
      }
      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('MCP: Column search failed: @error', ['@error' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Get per-column statistics for a datastore resource.
   */
  public function getDatastoreStats(string $resourceId, ?string $columns = NULL): array {
    try {
      [$identifier, $version] = $this->parseResourceId($resourceId);
      $storage = $this->datastoreService->getStorage($identifier, $version);
      $schema = $storage->getSchema();

      $fields = [];
      foreach ($schema['fields'] ?? [] as $name => $definition) {
        if ($name === 'record_number') {
          continue;
        }
        $fields[$name] = $definition;
      }

      // Filter to requested columns if specified.
      if ($columns !== NULL && $columns !== '') {
        $requested = array_map('trim', explode(',', $columns));
        $requested = $this->canonicalizeColumnNames($resourceId, $requested);
        $unknown = array_diff($requested, array_keys($fields));
        if ($unknown) {
          return ['error' => 'Unknown columns: ' . implode(', ', $unknown)];
        }
        $fields = array_intersect_key($fields, array_flip($requested));
      }

      $tableName = $storage->getTableName();
      $query = $this->database->select($tableName, 't');
      $query->addExpression('COUNT(*)', 'total_rows');

      foreach (array_keys($fields) as $col) {
        $query->addExpression("COUNT(\"$col\")", "{$col}__non_null");
        $query->addExpression("COUNT(DISTINCT \"$col\")", "{$col}__distinct");
        $query->addExpression("MIN(\"$col\")", "{$col}__min");
        $query->addExpression("MAX(\"$col\")", "{$col}__max");
      }

      $row = $query->execute()->fetchAssoc();

      $totalRows = (int) ($row['total_rows'] ?? 0);
      $columnStats = [];
      foreach ($fields as $name => $definition) {
        $nonNull = (int) ($row["{$name}__non_null"] ?? 0);
        $columnStats[] = [
          'name' => $name,
          'type' => $definition['type'] ?? 'unknown',
          'null_count' => $totalRows - $nonNull,
          'distinct_count' => (int) ($row["{$name}__distinct"] ?? 0),
          'min' => $row["{$name}__min"],
          'max' => $row["{$name}__max"],
        ];
      }

      return [
        'resource_id' => $resourceId,
        'total_rows' => $totalRows,
        'columns' => $columnStats,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('MCP: Stats query failed for @id: @error', ['@id' => $resourceId, '@error' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Validate and build expression property objects from JSON input.
   *
   * @return array
   *   ['expressions' => [...property objects...]] or ['error' => message].
   */
  protected function validateAndBuildExpressions(string $expressionsJson, array $reservedNames): array {
    $parsed = json_decode($expressionsJson, TRUE);
    if (!is_array($parsed) || !array_is_list($parsed)) {
      return ['error' => 'Invalid expressions: must be a JSON array of expression objects, e.g. [{"operator":"sum","operands":["column"],"alias":"total"}]'];
    }

    $aggregateOperators = ['sum', 'count', 'avg', 'max', 'min'];
    $arithmeticOperators = ['+', '-', '*', '/', '%'];
    $validOperators = array_merge($aggregateOperators, $arithmeticOperators);

    $expressions = [];
    foreach ($parsed as $expr) {
      if (empty($expr['operator']) || empty($expr['operands']) || empty($expr['alias'])) {
        return ['error' => 'Each expression must have operator, operands, and alias fields.'];
      }
      if (!in_array($expr['operator'], $validOperators, TRUE)) {
        return ['error' => 'Invalid operator "' . $expr['operator'] . '". Valid operators: ' . implode(', ', $validOperators)];
      }
      // Operand count validation.
      $operandCount = count($expr['operands']);
      if (in_array($expr['operator'], $aggregateOperators, TRUE) && $operandCount !== 1) {
        return ['error' => 'Aggregate operator "' . $expr['operator'] . '" requires exactly 1 operand, got ' . $operandCount . '.'];
      }
      if (in_array($expr['operator'], $arithmeticOperators, TRUE) && $operandCount !== 2) {
        return ['error' => 'Arithmetic operator "' . $expr['operator'] . '" requires exactly 2 operands, got ' . $operandCount . '.'];
      }
      if (in_array($expr['alias'], $reservedNames, TRUE)) {
        return ['error' => 'Expression alias "' . $expr['alias'] . '" conflicts with a column or grouping name. Use a distinct alias.'];
      }
      $reservedNames[] = $expr['alias'];
      $expressions[] = [
        'expression' => [
          'operator' => $expr['operator'],
          'operands' => $expr['operands'],
        ],
        'alias' => $expr['alias'],
      ];
    }

    // Check for mixing aggregate and arithmetic operators.
    $usedTypes = $this->classifyExpressionOperators($parsed, $aggregateOperators, $arithmeticOperators);
    if ($usedTypes['aggregate'] && $usedTypes['arithmetic']) {
      return ['error' => 'Cannot mix aggregate (sum, count, avg, max, min) and arithmetic (+, -, *, /, %) operators in the same query. DKAN does not support this combination.'];
    }

    return ['expressions' => $expressions];
  }

  /**
   * Classify whether expressions use aggregate, arithmetic, or both operators.
   *
   * Recursively inspects operands to detect nested expressions.
   */
  protected function classifyExpressionOperators(array $expressions, array $aggregateOperators, array $arithmeticOperators): array {
    $result = ['aggregate' => FALSE, 'arithmetic' => FALSE];
    foreach ($expressions as $expr) {
      if (!is_array($expr) || empty($expr['operator'])) {
        continue;
      }
      if (in_array($expr['operator'], $aggregateOperators, TRUE)) {
        $result['aggregate'] = TRUE;
      }
      if (in_array($expr['operator'], $arithmeticOperators, TRUE)) {
        $result['arithmetic'] = TRUE;
      }
      // Check nested expression operands.
      foreach ($expr['operands'] ?? [] as $operand) {
        if (is_array($operand) && isset($operand['operator'])) {
          $nested = $this->classifyExpressionOperators([$operand], $aggregateOperators, $arithmeticOperators);
          $result['aggregate'] = $result['aggregate'] || $nested['aggregate'];
          $result['arithmetic'] = $result['arithmetic'] || $nested['arithmetic'];
        }
      }
    }
    return $result;
  }

  /**
   * Get column names from a resource's schema, excluding record_number.
   */
  protected function getSchemaColumnNames(string $resourceId): array {
    if (isset($this->schemaColumnsCache[$resourceId])) {
      return $this->schemaColumnsCache[$resourceId];
    }
    try {
      [$identifier, $version] = $this->parseResourceId($resourceId);
      $storage = $this->datastoreService->getStorage($identifier, $version);
      $schema = $storage->getSchema();
      $columns = array_keys($schema['fields'] ?? []);
      $columns = array_values(array_filter($columns, fn($c) => $c !== 'record_number'));
      return $this->schemaColumnsCache[$resourceId] = $columns;
    }
    catch (\Exception) {
      return $this->schemaColumnsCache[$resourceId] = [];
    }
  }

  /**
   * Map user-supplied column names to canonical schema casing.
   *
   * Forgives case mismatches between what the LLM (or any caller) typed
   * and the column names actually stored in the schema, eliminating a
   * recurring class of "unknown_column" errors that wastes a turn even
   * though `available_columns` makes recovery possible.
   *
   * Behavior:
   *  - Exact match wins (no rewriting needed).
   *  - When no exact match exists but exactly one case-insensitive match
   *    does, rewrite to the schema's casing.
   *  - Multiple CI matches (e.g. schema has both `Date` and `date`) are
   *    ambiguous: pass the input through unchanged so the downstream
   *    error path stays authoritative.
   *  - Empty schema (lookup failed): pass through unchanged.
   *
   * @param string $resourceId
   *   The (already-resolved) resource id whose schema to canonicalize against.
   * @param string[] $columns
   *   Caller-supplied column names. Order is preserved.
   *
   * @return string[]
   *   Same order, with case corrected where a unique CI match exists.
   */
  protected function canonicalizeColumnNames(string $resourceId, array $columns): array {
    if (!$columns) {
      return $columns;
    }
    $schemaColumns = $this->getSchemaColumnNames($resourceId);
    if (!$schemaColumns) {
      return $columns;
    }
    $exact = array_flip($schemaColumns);
    $byLower = [];
    foreach ($schemaColumns as $canonical) {
      $byLower[strtolower($canonical)][] = $canonical;
    }
    $out = [];
    foreach ($columns as $col) {
      if (isset($exact[$col])) {
        $out[] = $col;
        continue;
      }
      $lower = strtolower($col);
      if (isset($byLower[$lower]) && count($byLower[$lower]) === 1) {
        $out[] = $byLower[$lower][0];
        continue;
      }
      $out[] = $col;
    }
    return $out;
  }

  /**
   * Decode HTML entities on the `operator` field of every condition.
   *
   * Some LLMs HTML-encode comparison operators when emitting JSON tool
   * arguments (e.g. `&gt;` instead of `>`). DKAN's DatastoreQuery enforces
   * a strict operator enum, so the encoded form fails validation and the
   * model can spin retrying the same broken JSON. We decode here so the
   * agent gets one error instead of N. Walks one level of nested AND/OR
   * groups for parity with property canonicalization. Touches `operator`
   * only — `value` may legitimately contain HTML entities.
   *
   * @param array $conditions
   *   Parsed conditions array from the JSON input.
   *
   * @return array
   *   The conditions array with HTML-encoded operators decoded.
   */
  protected static function canonicalizeOperators(array $conditions): array {
    $out = [];
    foreach ($conditions as $cond) {
      if (is_array($cond) && isset($cond['operator']) && is_string($cond['operator'])) {
        $cond['operator'] = html_entity_decode($cond['operator'], ENT_QUOTES | ENT_HTML5);
      }
      if (is_array($cond) && isset($cond['conditions']) && is_array($cond['conditions'])) {
        $cond['conditions'] = self::canonicalizeOperators($cond['conditions']);
      }
      $out[] = $cond;
    }
    return $out;
  }

  /**
   * Canonicalize the `property` field of every condition in-place.
   *
   * Walks one level of nested condition groups so AND/OR groupings get
   * the same case-correction as flat conditions. Anything else is
   * passed through untouched.
   *
   * @param array $conditions
   *   Parsed conditions array from the JSON input.
   * @param string $resourceId
   *   The resource id whose schema to canonicalize against.
   *
   * @return array
   *   The conditions array with property names case-corrected.
   */
  protected function canonicalizeConditionProperties(array $conditions, string $resourceId): array {
    $properties = [];
    foreach ($conditions as $cond) {
      if (is_array($cond) && isset($cond['property'])) {
        $properties[] = (string) $cond['property'];
      }
      if (is_array($cond) && isset($cond['conditions']) && is_array($cond['conditions'])) {
        foreach ($cond['conditions'] as $sub) {
          if (is_array($sub) && isset($sub['property'])) {
            $properties[] = (string) $sub['property'];
          }
        }
      }
    }
    if (!$properties) {
      return $conditions;
    }
    $canonical = $this->canonicalizeColumnNames($resourceId, $properties);
    $map = array_combine($properties, $canonical);
    $out = [];
    foreach ($conditions as $cond) {
      if (is_array($cond) && isset($cond['property']) && isset($map[$cond['property']])) {
        $cond['property'] = $map[$cond['property']];
      }
      if (is_array($cond) && isset($cond['conditions']) && is_array($cond['conditions'])) {
        $sub = [];
        foreach ($cond['conditions'] as $c) {
          if (is_array($c) && isset($c['property']) && isset($map[$c['property']])) {
            $c['property'] = $map[$c['property']];
          }
          $sub[] = $c;
        }
        $cond['conditions'] = $sub;
      }
      $out[] = $cond;
    }
    return $out;
  }

  /**
   * Build a successful query response with sanity flags.
   *
   * @param array $results
   *   Result rows from DatastoreQuery.
   * @param int $totalRows
   *   Total matching rows reported by DKAN's count.
   * @param int $limit
   *   The clamped row cap applied to this query.
   * @param int $offset
   *   The pagination offset.
   * @param string $resourceId
   *   The primary resource id (for coverage_warning column lookups).
   * @param array|null $conditions
   *   Parsed condition list (for coverage_warning detection).
   */
  protected function buildSuccessResponse(
    array $results,
    int $totalRows,
    int $limit,
    int $offset,
    string $resourceId,
    ?array $conditions,
  ): array {
    $resultCount = count($results);
    $sanity = [
      'zero_rows' => $resultCount === 0,
      'all_null_columns' => $this->detectAllNullColumns($results),
      'row_cap_hit' => $resultCount >= $limit && $totalRows > $resultCount,
      'coverage_warning' => NULL,
    ];
    if ($sanity['zero_rows'] && $conditions) {
      $sanity['coverage_warning'] = $this->maybeBuildCoverageWarning($conditions, $resourceId);
    }
    return [
      'results' => $results,
      'result_count' => $resultCount,
      'total_rows' => $totalRows,
      'limit' => $limit,
      'offset' => $offset,
      'sanity_flags' => $sanity,
    ];
  }

  /**
   * Build a structured error response, detecting unknown_column patterns.
   *
   * Returns a payload the agent can read and self-correct from rather than an
   * opaque exception message.
   */
  protected function buildErrorResponse(\Exception $e, string $resourceId): array {
    $message = $e->getMessage();
    $column = $this->extractUnknownColumn($message);
    if ($column !== NULL) {
      return [
        'error' => 'unknown_column',
        'column' => $column,
        'available_columns' => $this->getSchemaColumnNames($resourceId),
        'resource_id' => $resourceId,
        'message' => $message,
      ];
    }
    return [
      'error' => $message,
      'resource_id' => $resourceId,
    ];
  }

  /**
   * Try to extract a column name from a column-not-found error message.
   *
   * Covers MySQL's "Unknown column 'X'" and DKAN QueryFactory's
   * "Bad query property" / generic property-not-found messages. Returns the
   * column name if found, NULL otherwise.
   */
  protected function extractUnknownColumn(string $message): ?string {
    // MySQL: "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'foo' in 'field list'"
    if (preg_match("/Unknown column ['\"`]([^'\"`]+)['\"`]/i", $message, $m)) {
      return $m[1];
    }
    // Generic: "column 'foo' does not exist", "property 'foo' not found".
    if (preg_match("/(?:column|property|field)\s+['\"`]([^'\"`]+)['\"`]\s+(?:does\s+not\s+exist|not\s+found|is\s+unknown)/i", $message, $m)) {
      return $m[1];
    }
    // DKAN QueryFactory: "Bad query property" — column name not in message.
    if (stripos($message, 'bad query property') !== FALSE) {
      return '(unknown)';
    }
    return NULL;
  }

  /**
   * Return columns whose value is NULL in every returned row.
   *
   * Skipped on empty result sets (would falsely flag every column).
   */
  protected function detectAllNullColumns(array $results): array {
    if (!$results) {
      return [];
    }
    $columns = array_keys($results[0]);
    $allNull = [];
    foreach ($columns as $col) {
      $sawValue = FALSE;
      foreach ($results as $row) {
        if (array_key_exists($col, $row) && $row[$col] !== NULL && $row[$col] !== '') {
          $sawValue = TRUE;
          break;
        }
      }
      if (!$sawValue) {
        $allNull[] = $col;
      }
    }
    return $allNull;
  }

  /**
   * If conditions filter on a date-like column and we got 0 rows, flag it.
   *
   * Cheap heuristic: looks at the schema for any column referenced in
   * conditions and checks if its type smells like a date. Avoids running
   * extra aggregation queries — the warning just nudges the agent to verify
   * coverage via getDatastoreStats.
   */
  protected function maybeBuildCoverageWarning(array $conditions, string $resourceId): ?string {
    try {
      [$identifier, $version] = $this->parseResourceId($resourceId);
      $storage = $this->datastoreService->getStorage($identifier, $version);
      $schema = $storage->getSchema();
      $fields = $schema['fields'] ?? [];
    }
    catch (\Throwable) {
      return NULL;
    }
    $dateCols = [];
    foreach ($conditions as $cond) {
      if (!is_array($cond) || empty($cond['property'])) {
        continue;
      }
      $col = is_string($cond['property']) ? $cond['property'] : ($cond['property']['property'] ?? NULL);
      if (!$col || !isset($fields[$col])) {
        continue;
      }
      $type = strtolower((string) ($fields[$col]['type'] ?? ''));
      if (str_contains($type, 'date') || str_contains($type, 'time') || str_contains($type, 'year')) {
        $dateCols[] = $col;
      }
    }
    if (!$dateCols) {
      return NULL;
    }
    return sprintf(
      "Filter on date-like column(s) [%s] returned 0 rows — verify the value is within the dataset's coverage window via get_datastore_stats.",
      implode(', ', $dateCols),
    );
  }

  /**
   * Parse a resource_id string into [identifier, version].
   *
   * @return array{string, string|null}
   *   The identifier and version.
   */
  protected function parseResourceId(string $resourceId): array {
    if (str_contains($resourceId, '__')) {
      $parts = explode('__', $resourceId, 2);
      return [$parts[0], $parts[1]];
    }
    return [$resourceId, NULL];
  }

}
