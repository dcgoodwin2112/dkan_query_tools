<?php

namespace Drupal\dkan_query_tools\Tool;

use Drupal\common\DatasetInfo;
use Drupal\metastore\MetastoreService;

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
   */
  public function listDatasets(int $offset = 0, int $limit = 25): array {
    $limit = min(max($limit, 1), 100);
    $datasets = $this->metastore->getAll('dataset', $offset, $limit);
    $total = $this->metastore->count('dataset');

    $items = [];
    foreach ($datasets as $dataset) {
      $data = json_decode((string) $dataset);
      $items[] = [
        'identifier' => $data->identifier ?? NULL,
        'title' => $data->title ?? NULL,
        'description' => isset($data->description) ? mb_substr($data->description, 0, 200) : NULL,
        'distributions' => isset($data->distribution) ? count($data->distribution) : 0,
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
          $distributions[] = [
            'identifier' => $distUuid,
            'resource_id' => $resourceId,
            'title' => $dist->title ?? NULL,
            'mediaType' => $dist->mediaType ?? NULL,
            'downloadURL' => $dist->downloadURL ?? NULL,
          ];
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
        if (isset($dataset['description'])) {
          $dataset['description'] = mb_substr($dataset['description'], 0, 200);
        }
        unset($dataset['spatial']);
      }
      unset($dataset);
    }

    return ['catalog' => $data];
  }

  /**
   * Get a JSON Schema definition by schema ID.
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
   * Get aggregated dataset info: distributions, resources, import status.
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
