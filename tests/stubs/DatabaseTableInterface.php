<?php

namespace Drupal\dkan_common\Storage;

/**
 * Stub for Drupal\dkan_common\Storage\DatabaseTableInterface.
 */
interface DatabaseTableInterface {

  public function getSchema(): array;

  public function getTableName(): string;

}
