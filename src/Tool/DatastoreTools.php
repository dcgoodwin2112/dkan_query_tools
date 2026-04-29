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

  public function __construct(
    protected DatastoreService $datastoreService,
    protected Query $queryService,
    protected MetastoreService $metastore,
    protected DatasetInfo $datasetInfo,
    protected Connection $database,
    protected LoggerInterface $logger,
  ) {}

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
  ): array {
    $limit = min(max($limit, 1), 500);

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
    }

    $groupList = $groupings ? array_map('trim', explode(',', $groupings)) : [];

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
      $query['conditions'] = $parsed;
    }

    if ($sortField) {
      $query['sorts'] = [
        [
          'property' => $sortField,
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

      return [
        'results' => $decoded['results'] ?? [],
        'result_count' => count($decoded['results'] ?? []),
        'total_rows' => $decoded['count'] ?? 0,
        'limit' => $limit,
        'offset' => $offset,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('MCP: Datastore query failed for @id: @error', ['@id' => $resourceId, '@error' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
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
   */
  public function getDatastoreSchema(string $resourceId): array {
    try {
      [$identifier, $version] = $this->parseResourceId($resourceId);
      $storage = $this->datastoreService->getStorage($identifier, $version);
      $schema = $storage->getSchema();

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
          $columns[] = $col;
        }
      }

      return ['resource_id' => $resourceId, 'columns' => $columns];
    }
    catch (\Exception $e) {
      return ['error' => $e->getMessage()];
    }
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
  ): array {
    $limit = min(max($limit, 1), 500);

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

      return [
        'results' => $decoded['results'] ?? [],
        'result_count' => count($decoded['results'] ?? []),
        'total_rows' => $decoded['count'] ?? 0,
        'limit' => $limit,
        'offset' => $offset,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('MCP: Datastore join query failed for @id: @error', ['@id' => $resourceId, '@error' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
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
    try {
      [$identifier, $version] = $this->parseResourceId($resourceId);
      $storage = $this->datastoreService->getStorage($identifier, $version);
      $schema = $storage->getSchema();
      $columns = array_keys($schema['fields'] ?? []);
      return array_values(array_filter($columns, fn($c) => $c !== 'record_number'));
    }
    catch (\Exception) {
      return [];
    }
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
