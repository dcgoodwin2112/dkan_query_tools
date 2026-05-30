<?php

namespace Drupal\dkan_datastore;

use Drupal\dkan_common\Storage\DatabaseTableInterface;

/**
 * Stub for Drupal\dkan_datastore\DatastoreService.
 */
class DatastoreService {

  public function getStorage(string $identifier, $version = NULL): DatabaseTableInterface {
    throw new \RuntimeException('Not implemented');
  }

  public function summary($identifier) {
    return [];
  }

  public function import(string $identifier, bool $deferred = FALSE, $version = NULL) {
    return [];
  }

  public function drop(string $identifier, ?string $version = NULL): void {
  }

}
