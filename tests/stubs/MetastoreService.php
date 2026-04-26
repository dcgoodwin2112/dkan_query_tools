<?php

namespace Drupal\metastore;

use RootedData\RootedJsonData;

/**
 * Stub for Drupal\metastore\MetastoreService.
 */
class MetastoreService {

  public function getAll(string $schema_id, ?int $start = NULL, ?int $length = NULL, $unpublished = FALSE): array {
    return [];
  }

  public function get(string $schema_id, string $identifier, bool $published = TRUE): RootedJsonData {
    return new RootedJsonData('{}');
  }

  public function count(string $schema_id, bool $unpublished = FALSE): int {
    return 0;
  }

  public function getSchemas() {
    return [];
  }

  public function getCatalog() {
    return new \stdClass();
  }

  public static function removeReferences(RootedJsonData $data): void {
    $json = (string) $data;
    $decoded = json_decode($json, TRUE);
    if (is_array($decoded)) {
      foreach (array_keys($decoded) as $key) {
        if (str_starts_with($key, '%Ref:')) {
          unset($decoded[$key]);
        }
      }
      $data->set('$', json_decode(json_encode($decoded)));
    }
  }

  public function post(string $schema_id, RootedJsonData $data): string {
    return '';
  }

  public function publish(string $schema_id, string $identifier): bool {
    return TRUE;
  }

  public function put(string $schema_id, string $identifier, RootedJsonData $data): array {
    return ['identifier' => $identifier, 'new' => FALSE];
  }

  public function patch(string $schema_id, string $identifier, mixed $json_data): string {
    return $identifier;
  }

  public function delete(string $schema_id, string $identifier): string {
    return $identifier;
  }

  public function archive(string $schema_id, string $identifier): bool {
    return TRUE;
  }

  public function getSchema(string $schema_id) {
    return new \stdClass();
  }

}
