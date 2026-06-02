<?php

namespace Drupal\dkan_query_tools\Tool;

use Drupal\dkan_common\DatasetInfo;
use Drupal\Core\Database\Connection;
use Drupal\dkan_datastore\DatastoreService;
use Drupal\dkan_datastore\Service\DatastoreQuery;
use Drupal\dkan_datastore\Service\Info\ImportInfo;
use Drupal\dkan_datastore\Service\Query;
use Drupal\dkan_metastore\MetastoreService;
use Procrastinator\Result;
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
   * The canonicalizeColumnNames() method can fire several times per query (one
   * per input axis: columns, groupings, sort, conditions); the memo keeps the
   * cost to a single getStorage()/getSchema() per resource per request.
   *
   * @var array<string, string[]>
   */
  protected array $schemaColumnsCache = [];

  /**
   * Per-instance memo of bare-UUID => [identifier, version|null] resolution.
   *
   * @var array<string, array{string, string|null}>
   */
  protected array $resourceIdCache = [];

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
    protected ?ImportInfo $importInfo = NULL,
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
   * Query a datastore resource: filter, sort, paginate, and aggregate.
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
    $offset = max($offset, 0);

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
      $parsed = self::canonicalizeOperators($parsed);
      if ($opError = self::validateOperators($parsed)) {
        return ['error' => $opError];
      }
      $query['conditions'] = $parsed;
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
      $this->logger->error('Datastore query failed for @id: @error', [
        '@id' => $resourceId,
        '@error' => $e->getMessage(),
      ]);
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
   *   Resource ID in identifier__version format.
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
      $this->logger->error('Sample rows failed for @id: @error', [
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
   *   Resource ID in identifier__version format.
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

      // Fetch limit+1 to detect truncation. Exclude NULLs in SQL (empty strings
      // are kept — they are real distinct values) so the truncation flag and the
      // returned value count are computed over the same non-null set rather than
      // diverging when a NULL occupies the +1 boundary row.
      $query = $this->database->select($tableName, 't');
      $query->addField('t', $column, 'value');
      $query->distinct();
      $query->isNotNull('t.' . $column);
      $query->orderBy('value', 'ASC');
      $query->range(0, $limit + 1);
      $rows = $query->execute()->fetchCol();

      $truncated = count($rows) > $limit;
      $values = array_values(array_slice($rows, 0, $limit));

      return [
        'resource_id' => $resourceId,
        'column' => $column,
        'values' => $values,
        'value_count' => count($values),
        'truncated' => $truncated,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Distinct values failed for @id.@col: @error', [
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
   *   Datastore resource ID (identifier__version) or a distribution UUID.
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
   *
   * @param string $resourceId
   *   Resource ID in identifier__version format (from list_distributions).
   */
  public function getImportStatus(string $resourceId): array {
    // Row/column counts come from the datastore summary; a thrown summary means
    // no datastore table exists for this resource yet.
    $numOfRows = NULL;
    $numOfColumns = NULL;
    $tableExists = FALSE;
    $summaryError = NULL;
    try {
      $summary = $this->datastoreService->summary($resourceId);
      $numOfRows = is_object($summary) ? ($summary->numOfRows ?? 0) : ($summary['numOfRows'] ?? 0);
      $numOfColumns = is_object($summary) ? ($summary->numOfColumns ?? 0) : ($summary['numOfColumns'] ?? 0);
      $tableExists = TRUE;
    }
    catch (\Throwable $e) {
      $summaryError = $e->getMessage();
    }

    $state = $this->resolveImportState($resourceId, $tableExists);

    $result = [
      'resource_id' => $resourceId,
      'status' => $state['status'],
      'num_of_rows' => $numOfRows,
      'num_of_columns' => $numOfColumns,
    ];
    if (isset($state['error'])) {
      $result['error'] = $state['error'];
    }
    elseif ($state['status'] === 'not_imported' && $summaryError !== NULL) {
      $result['error'] = $summaryError;
    }
    return $result;
  }

  /**
   * Resolve a normalized import status for a resource.
   *
   * Prefers DKAN's authoritative importer job state (via ImportInfo) so a
   * completed import is reported "done" regardless of row count — a header-only
   * (zero-row) CSV import is a valid, completed datastore table, not "pending".
   *
   * Both pipeline stages are consulted. The datastore import queues only after
   * the file fetcher finishes, so a fetcher that is done or running while the
   * importer still reads WAITING means datastore work is queued/imminent —
   * "pending", not "not_imported" (a queued deferred import) and not "done" (a
   * queued re-import over an older table). Only when neither stage has started
   * (fetcher WAITING) is the resource genuinely un-queued, and the state is
   * resolved from table existence.
   *
   * When ImportInfo is unavailable (e.g. unit tests with no version), falls
   * back to datastore-table existence rather than row count.
   *
   * @param string $resourceId
   *   Resource ID, ideally in identifier__version format.
   * @param bool $tableExists
   *   Whether a datastore table currently exists for the resource.
   *
   * @return array{status: string, error?: string}
   *   Normalized status (done|pending|error|not_imported) and optional error.
   */
  protected function resolveImportState(string $resourceId, bool $tableExists): array {
    [$identifier, $version] = $this->parseResourceId($resourceId);

    if ($this->importInfo !== NULL && $version !== NULL) {
      try {
        $item = $this->importInfo->getItem($identifier, (string) $version);
        $importer = $item->importerStatus ?? NULL;
        $fetcher = $item->fileFetcherStatus ?? NULL;

        // Terminal importer states are authoritative.
        if ($importer === Result::DONE) {
          return ['status' => 'done'];
        }
        if ($importer === Result::ERROR) {
          return ['status' => 'error', 'error' => $item->importerError ?: 'Import failed.'];
        }
        // Datastore import actively running (or partially run, will resume).
        if ($importer === Result::IN_PROGRESS || $importer === Result::STOPPED) {
          return ['status' => 'pending'];
        }
        // Importer still WAITING: look at the fetch stage. A failed fetch is a
        // hard error; a fetch that is running or already done means the
        // datastore import is queued or imminent — pending, regardless of any
        // older table.
        if ($fetcher === Result::ERROR) {
          return ['status' => 'error', 'error' => 'File fetch failed before datastore import.'];
        }
        if ($fetcher === Result::IN_PROGRESS || $fetcher === Result::DONE) {
          return ['status' => 'pending'];
        }
        // Neither stage has started: not queued. Resolve from table existence.
        return ['status' => $tableExists ? 'done' : 'not_imported'];
      }
      catch (\Throwable) {
        // Fall through to the storage-based heuristic below.
      }
    }

    // Fallback: storage existence, not row count.
    return ['status' => $tableExists ? 'done' : 'not_imported'];
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
    $offset = max($offset, 0);

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
      $parsed = self::canonicalizeOperators($parsed);
      if ($opError = self::validateOperators($parsed)) {
        return ['error' => $opError];
      }
      $query['conditions'] = $parsed;
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
      $this->logger->error('Datastore join query failed for @id: @error', [
        '@id' => $resourceId,
        '@error' => $e->getMessage(),
      ]);
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
      // left/right must be non-empty strings: a JSON array/object/number would
      // otherwise reach parseQualifiedField(string) and throw an uncaught
      // TypeError (this runs before the query try/catch below).
      if (!is_array($parsed)
        || empty($parsed['left']) || !is_string($parsed['left'])
        || empty($parsed['right']) || !is_string($parsed['right'])) {
        return ['error' => 'Invalid JSON join_on: must have non-empty string "left" and "right" fields (e.g., {"left":"t.col","right":"j.col","operator":"="}).'];
      }
      $left = $this->qualifyJoinField($parsed['left'], 't');
      $right = $this->qualifyJoinField($parsed['right'], 'j');

      $condition = [
        'resource' => $left['resource'],
        'property' => $left['property'],
        'value' => $right,
      ];

      // Optional non-equality operator. DKAN supports =, !=, <>, <, <=, >, >=,
      // like for join conditions; without this the documented "operator" field
      // was silently dropped and every join ran as equality.
      if (isset($parsed['operator']) && $parsed['operator'] !== '' && $parsed['operator'] !== '=') {
        $op = is_string($parsed['operator']) ? $this->normalizeJoinOperator($parsed['operator']) : NULL;
        if ($op === NULL) {
          return ['error' => 'Invalid join operator. Valid operators: =, !=, <>, <, <=, >, >=, like.'];
        }
        $condition['operator'] = $op;
      }

      // A two-resource join always attaches the joined resource 'j'; the
      // condition carries the t/j relationship. (Deriving this from the right
      // side mis-joined when the right was qualified to 't', e.g. "j.x=t.y".)
      return ['resource' => 'j', 'condition' => $condition];
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

    // Unqualified columns default to left→primary 't', right→joined 'j'.
    $left = $this->qualifyJoinField($leftCol, 't');
    $right = $this->qualifyJoinField($rightCol, 'j');

    return [
      'resource' => 'j',
      'condition' => [
        'resource' => $left['resource'],
        'property' => $left['property'],
        'value' => $right,
      ],
    ];
  }

  /**
   * Parse a join field, defaulting an unqualified column to the given resource.
   *
   * @param string $field
   *   Field as "alias.column" or a bare "column".
   * @param string $defaultResource
   *   Resource alias to use when the field carries no "alias." prefix.
   *
   * @return array{resource: string, property: string}
   *   Resolved resource alias and property.
   */
  protected function qualifyJoinField(string $field, string $defaultResource): array {
    $parsed = $this->parseQualifiedField($field);
    if (!str_contains($field, '.')) {
      $parsed['resource'] = $defaultResource;
    }
    return $parsed;
  }

  /**
   * Normalize a join operator to a DKAN-accepted form, or NULL if invalid.
   *
   * DKAN's SelectFactory::safeJoinOperator accepts =, !=, <>, >, >=, <, <=, and
   * (case-sensitive) LIKE. Symbols pass through; "like" is upper-cased.
   */
  protected function normalizeJoinOperator(string $operator): ?string {
    $symbols = ['=', '!=', '<>', '>', '>=', '<', '<='];
    if (in_array($operator, $symbols, TRUE)) {
      return $operator;
    }
    if (strtolower($operator) === 'like') {
      return 'LIKE';
    }
    return NULL;
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
   *
   * @param string $searchTerm
   *   Column name or description substring to search (case-insensitive).
   * @param string $searchIn
   *   Where to search: "name", "description", or "both".
   * @param int $limit
   *   Max matches to return (default 100).
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

    $limit = min(max($limit, 1), 500);

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
      $this->logger->error('Column search failed: @error', ['@error' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Get per-column statistics for a datastore resource.
   *
   * @param string $resourceId
   *   Datastore resource ID (identifier__version) or a distribution UUID.
   * @param string|null $columns
   *   Comma-separated column names to analyze. Omit for all columns.
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
        // Quote the identifier with the driver's identifier-quote char
        // (backticks on default MySQL). Hardcoded double quotes are treated as
        // a string literal unless ANSI_QUOTES is set, which would make every
        // aggregate operate on a constant. escapeField also strips any char
        // outside [A-Za-z0-9_.]; $col is already schema-validated above.
        $field = $this->database->escapeField($col);
        $query->addExpression("COUNT($field)", "{$col}__non_null");
        $query->addExpression("COUNT(DISTINCT $field)", "{$col}__distinct");
        $query->addExpression("MIN($field)", "{$col}__min");
        $query->addExpression("MAX($field)", "{$col}__max");
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
      $this->logger->error('Stats query failed for @id: @error', [
        '@id' => $resourceId,
        '@error' => $e->getMessage(),
      ]);
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
      // Validate types from the decoded JSON before any use: a non-array $expr,
      // a non-string operator/alias, or a non-array operands would otherwise
      // throw (e.g. count() on a scalar) outside the query try/catch.
      if (!is_array($expr)
        || empty($expr['operator']) || !is_string($expr['operator'])
        || empty($expr['operands']) || !is_array($expr['operands'])
        || empty($expr['alias']) || !is_string($expr['alias'])) {
        return ['error' => 'Each expression must have a string operator, a non-empty operands array, and a string alias.'];
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
   * Reject conditions with an operator outside DKAN's allowed enum.
   *
   * Should run AFTER canonicalizeOperators() so HTML-encoded forms like
   * `&gt;` get rescued before validation. The empty-operator case is the
   * one that motivated this guard: both Anthropic and OpenAI models
   * occasionally drop the literal `<` character on its way to tool-call
   * JSON and emit `"operator": ""`. RootedJsonData rejects that with the
   * generic "JSON Schema validation failed.", which gives the agent no
   * field name and no enum hint to recover from. A friendly,
   * field-named error lets the agent self-correct on the next turn
   * (and helps Haiku-class models avoid hallucinating from cached
   * data when they exhaust retries).
   *
   * Walks the same nested AND/OR group shape as canonicalizeOperators.
   *
   * @param array $conditions
   *   Parsed and canonicalized conditions array.
   *
   * @return string|null
   *   A friendly error message naming the offending property and
   *   listing the allowed operators, or NULL if every condition is
   *   valid. Returns the FIRST violation found.
   */
  protected static function validateOperators(array $conditions): ?string {
    // Strict enum from DKAN's query.json schema (case-sensitive).
    static $strict = ['=', '<>', '<', '<=', '>', '>='];
    // Alphanumeric operators (case-insensitive in the schema).
    static $alpha = ['like', 'between', 'in', 'not in', 'contains', 'starts with', 'match'];

    foreach ($conditions as $cond) {
      if (!is_array($cond)) {
        continue;
      }
      if (isset($cond['conditions']) && is_array($cond['conditions'])) {
        $err = self::validateOperators($cond['conditions']);
        if ($err !== NULL) {
          return $err;
        }
        continue;
      }
      // Operator absent or non-string: leave it to RootedJsonData. The
      // schema applies a default of "=" when the field is missing
      // entirely, and we shouldn't override that here.
      if (!isset($cond['operator']) || !is_string($cond['operator'])) {
        continue;
      }
      $op = $cond['operator'];
      if (in_array($op, $strict, TRUE) || in_array(strtolower($op), $alpha, TRUE)) {
        continue;
      }
      $property = (isset($cond['property']) && is_string($cond['property']) && $cond['property'] !== '')
        ? $cond['property']
        : '(unknown)';
      $opDisplay = $op === '' ? 'is empty' : 'is ' . var_export($op, TRUE);
      return sprintf(
        'Invalid condition for property "%s": operator %s. Operator must be one of: %s.',
        $property,
        $opDisplay,
        implode(', ', array_merge($strict, $alpha))
      );
    }
    return NULL;
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
      if (is_array($cond) && isset($cond['property']) && is_string($cond['property'])) {
        $properties[] = $cond['property'];
      }
      if (is_array($cond) && isset($cond['conditions']) && is_array($cond['conditions'])) {
        foreach ($cond['conditions'] as $sub) {
          if (is_array($sub) && isset($sub['property']) && is_string($sub['property'])) {
            $properties[] = $sub['property'];
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
      if (is_array($cond) && isset($cond['property']) && is_string($cond['property']) && isset($map[$cond['property']])) {
        $cond['property'] = $map[$cond['property']];
      }
      if (is_array($cond) && isset($cond['conditions']) && is_array($cond['conditions'])) {
        $sub = [];
        foreach ($cond['conditions'] as $c) {
          if (is_array($c) && isset($c['property']) && is_string($c['property']) && isset($map[$c['property']])) {
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
    // MySQL form, e.g. "Unknown column 'foo' in 'field list'".
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
   * Parse or resolve a resource ID into [identifier, version].
   *
   * Accepts a datastore resource ID in identifier__version format, or a bare
   * distribution UUID — mirroring DKAN's /api/1/datastore/query/{identifier}
   * route, which accepts either. A bare UUID is resolved to its underlying
   * identifier__version by reading the distribution's %Ref:downloadURL. When
   * resolution fails the input is returned as-is ([identifier, NULL]) so a
   * true bare identifier keeps its prior behavior. Per-instance memoized.
   *
   * @return array{string, string|null}
   *   The identifier and version.
   */
  protected function parseResourceId(string $resourceId): array {
    if (str_contains($resourceId, '__')) {
      $parts = explode('__', $resourceId, 2);
      return [$parts[0], $parts[1]];
    }
    if (array_key_exists($resourceId, $this->resourceIdCache)) {
      return $this->resourceIdCache[$resourceId];
    }
    return $this->resourceIdCache[$resourceId]
      = $this->resolveDistributionUuid($resourceId) ?? [$resourceId, NULL];
  }

  /**
   * Resolve a distribution UUID to [identifier, version] via the metastore.
   *
   * @return array{string, string}|null
   *   The resource identifier and version, or NULL when the UUID is not a
   *   distribution or has no resource reference.
   */
  protected function resolveDistributionUuid(string $uuid): ?array {
    try {
      $distribution = $this->metastore->get('distribution', $uuid);
    }
    catch (\Throwable) {
      return NULL;
    }
    $data = json_decode((string) $distribution);
    if (!is_object($data) || !isset($data->data->{'%Ref:downloadURL'}[0]->data)) {
      return NULL;
    }
    $ref = $data->data->{'%Ref:downloadURL'}[0]->data;
    if (empty($ref->identifier) || !isset($ref->version)) {
      return NULL;
    }
    return [(string) $ref->identifier, (string) $ref->version];
  }

}
