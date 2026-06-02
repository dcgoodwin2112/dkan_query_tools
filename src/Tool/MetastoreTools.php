<?php

namespace Drupal\dkan_query_tools\Tool;

use Drupal\dkan_common\DatasetInfo;
use Drupal\dkan_metastore\MetastoreService;

/**
 * Tools for DKAN metastore operations.
 */
class MetastoreTools {

  public function __construct(
    protected MetastoreService $metastore,
    protected DatasetInfo $datasetInfo,
  ) {}

  /**
   * List dataset summaries with pagination.
   *
   * @param int $offset
   *   Number of datasets to skip.
   * @param int $limit
   *   Max datasets to return (1-100).
   */
  public function listDatasets(int $offset = 0, int $limit = 25): array {
    $limit = min(max($limit, 1), 100);
    $offset = max($offset, 0);
    $datasets = $this->metastore->getAll('dataset', $offset, $limit);
    $total = $this->metastore->count('dataset');

    $items = [];
    foreach ($datasets as $dataset) {
      $data = json_decode((string) $dataset);
      $items[] = [
        'identifier' => $data->identifier ?? NULL,
        'title' => $data->title ?? NULL,
        'description' => isset($data->description) && is_scalar($data->description) ? mb_substr((string) $data->description, 0, 200) : NULL,
        'distributions' => is_countable($data->distribution ?? NULL) ? count($data->distribution) : 0,
      ];
    }

    // Adjust total if the full result set fits in one page,
    // since count() may include items that fail validation.
    if ($offset === 0 && count($items) < $limit) {
      $total = count($items);
    }

    return [
      'datasets' => $items,
      'total' => $total,
      'offset' => $offset,
      'limit' => $limit,
    ];
  }

  /**
   * Get full dataset metadata by UUID.
   *
   * @param string $identifier
   *   Dataset UUID.
   */
  public function getDataset(string $identifier): array {
    try {
      $dataset = $this->metastore->get('dataset', $identifier);
      $decoded = json_decode((string) $dataset, TRUE);
      return ['dataset' => self::stripInternalKeys($decoded)];
    }
    catch (\Exception $e) {
      return ['error' => 'Dataset not found: ' . $identifier];
    }
  }

  /**
   * List distributions for a dataset.
   *
   * @param string $datasetId
   *   Dataset UUID.
   */
  public function listDistributions(string $datasetId): array {
    try {
      $dataset = $this->metastore->get('dataset', $datasetId);
      $data = json_decode((string) $dataset);
      $distributions = [];

      if (isset($data->distribution)) {
        $refs = $data->{'%Ref:distribution'} ?? [];
        foreach ($data->distribution as $i => $dist) {
          // Extract the resource identifier from %Ref:downloadURL for
          // use with datastore tools (query_datastore, get_datastore_schema).
          $resourceId = NULL;
          if (isset($dist->{'%Ref:downloadURL'}[0]->data)) {
            $ref = $dist->{'%Ref:downloadURL'}[0]->data;
            $resourceId = ($ref->identifier ?? '') . '__' . ($ref->version ?? '');
          }
          // Distribution UUIDs are in %Ref:distribution, not in the
          // embedded distribution objects.
          $distUuid = isset($refs[$i]) ? ($refs[$i]->identifier ?? NULL) : NULL;
          $entry = [
            'identifier' => $distUuid,
            'resource_id' => $resourceId,
            'title' => $dist->title ?? NULL,
            'mediaType' => $dist->mediaType ?? NULL,
            'downloadURL' => $dist->downloadURL ?? NULL,
          ];
          if (!empty($dist->describedBy)) {
            $entry['describedBy'] = $dist->describedBy;
          }
          if (!empty($dist->describedByType)) {
            $entry['describedByType'] = $dist->describedByType;
          }
          $distributions[] = $entry;
        }
      }

      return ['distributions' => $distributions];
    }
    catch (\Exception $e) {
      return ['error' => 'Dataset not found: ' . $datasetId];
    }
  }

  /**
   * Get distribution metadata by UUID.
   *
   * @param string $identifier
   *   Distribution UUID.
   */
  public function getDistribution(string $identifier): array {
    try {
      $distribution = $this->metastore->get('distribution', $identifier);
      $decoded = json_decode((string) $distribution, TRUE);
      return ['distribution' => self::stripInternalKeys($decoded)];
    }
    catch (\Exception $e) {
      return ['error' => 'Distribution not found: ' . $identifier];
    }
  }

  /**
   * List available schema IDs.
   */
  public function listSchemas(): array {
    return ['schemas' => array_keys($this->metastore->getSchemas())];
  }

  /**
   * Get the full DCAT catalog.
   */
  public function getCatalog(): array {
    $catalog = $this->metastore->getCatalog();
    $data = json_decode(json_encode($catalog), TRUE);

    // Truncate descriptions and strip verbose fields to reduce token usage.
    if (isset($data['dataset'])) {
      foreach ($data['dataset'] as &$dataset) {
        if (isset($dataset['description']) && is_scalar($dataset['description'])) {
          $dataset['description'] = mb_substr((string) $dataset['description'], 0, 200);
        }
        unset($dataset['spatial']);
      }
      unset($dataset);
    }

    return ['catalog' => $data];
  }

  /**
   * Get a JSON Schema definition by schema ID.
   *
   * @param string $schemaId
   *   Schema ID (e.g. dataset, distribution, keyword).
   */
  public function getSchema(string $schemaId): array {
    try {
      $schema = $this->metastore->getSchema($schemaId);
      return [
        'schema_id' => $schemaId,
        'schema' => is_string($schema) ? json_decode($schema, TRUE) : json_decode(json_encode($schema), TRUE),
      ];
    }
    catch (\Throwable $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Recursively strip all %-prefixed internal keys from decoded JSON data.
   */
  private static function stripInternalKeys(array $data): array {
    foreach ($data as $key => $value) {
      if (is_string($key) && str_starts_with($key, '%')) {
        unset($data[$key]);
      }
      elseif (is_array($value)) {
        $data[$key] = self::stripInternalKeys($value);
      }
    }
    return $data;
  }

  /**
   * Get the data dictionary linked to a dataset or resource.
   *
   * Accepts either a dataset UUID or a resource_id (identifier__version).
   * For a resource_id, walks the dataset list (capped at 200) to find the
   * owning distribution and reads its inline `describedBy` URL. For a
   * dataset UUID, reads the dataset directly and inspects every linked
   * distribution.
   *
   * Known limitation: only finds dictionaries linked on inline distributions
   * (the DCAT-flat shape DKAN currently stores). Dictionaries linked via
   * standalone `distribution` metastore items would require a separate walk.
   *
   * @param string $datasetOrResourceId
   *   Dataset UUID or resource ID in identifier__version format.
   *
   * @return array
   *   ['dictionaries' => [resource_id => entry]] or ['error' => message]
   *   where entry is ['identifier','url','title','fields'].
   */
  public function getDataDictionary(string $datasetOrResourceId): array {
    $isResourceId = str_contains($datasetOrResourceId, '__');
    try {
      if ($isResourceId) {
        $found = $this->findDistributionByResourceId($datasetOrResourceId);
        if (!$found) {
          return ['error' => 'No distribution found for resource: ' . $datasetOrResourceId];
        }
        $links = [
          [
            'resource_id' => $datasetOrResourceId,
            'describedBy' => $found,
          ],
        ];
      }
      else {
        $dataset = $this->metastore->get('dataset', $datasetOrResourceId);
        $links = $this->collectDictionaryLinks(json_decode((string) $dataset));
        if (!$links) {
          return ['error' => 'No data dictionary linked to dataset: ' . $datasetOrResourceId];
        }
      }
    }
    catch (\Throwable $e) {
      return ['error' => $e->getMessage()];
    }

    $dictionaries = [];
    foreach ($links as $link) {
      $dictId = $this->extractDictionaryIdentifier((string) $link['describedBy']);
      if ($dictId === NULL) {
        continue;
      }
      try {
        $doc = $this->metastore->get('data-dictionary', $dictId);
      }
      catch (\Throwable) {
        continue;
      }
      $decoded = json_decode((string) $doc, TRUE);
      $dictionaries[$link['resource_id'] ?? $dictId] = [
        'identifier' => $dictId,
        'url' => $link['describedBy'],
        'title' => $decoded['data']['title'] ?? NULL,
        'fields' => $decoded['data']['fields'] ?? [],
      ];
    }

    if (!$dictionaries) {
      return ['error' => 'Linked dictionary could not be fetched.'];
    }
    return ['dictionaries' => $dictionaries];
  }

  /**
   * Collect (resource_id, describedBy) pairs from a dataset's distributions.
   */
  protected function collectDictionaryLinks(?object $dataset): array {
    if (!$dataset || !isset($dataset->distribution) || !is_array($dataset->distribution)) {
      return [];
    }
    $links = [];
    foreach ($dataset->distribution as $dist) {
      if (empty($dist->describedBy)) {
        continue;
      }
      $resourceId = NULL;
      $ref = $dist->{'%Ref:downloadURL'}[0]->data ?? NULL;
      if ($ref) {
        $resourceId = ($ref->identifier ?? '') . '__' . ($ref->version ?? '');
      }
      $links[] = [
        'resource_id' => $resourceId,
        'describedBy' => $dist->describedBy,
      ];
    }
    return $links;
  }

  /**
   * Walk datasets to find a distribution's inline `describedBy` by resource_id.
   *
   * @return string|null
   *   The describedBy URL or NULL when no match / no link.
   */
  protected function findDistributionByResourceId(string $resourceId): ?string {
    if (!str_contains($resourceId, '__')) {
      return NULL;
    }
    [$wantId, $wantVersion] = explode('__', $resourceId, 2);
    $datasets = $this->metastore->getAll('dataset', 0, 200);
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
        return !empty($dist->describedBy) ? (string) $dist->describedBy : NULL;
      }
    }
    return NULL;
  }

  /**
   * Extract the dictionary identifier from a describedBy URL.
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
   * Get aggregated dataset info: distributions, resources, import status.
   *
   * @param string $uuid
   *   Dataset UUID.
   */
  public function getDatasetInfo(string $uuid): array {
    try {
      $info = $this->datasetInfo->gather($uuid);
      if (isset($info['notice'])) {
        return ['error' => $info['notice'] . ': ' . $uuid];
      }
      return ['dataset_info' => $info];
    }
    catch (\Throwable $e) {
      return ['error' => 'Failed to gather dataset info: ' . $e->getMessage()];
    }
  }

}
