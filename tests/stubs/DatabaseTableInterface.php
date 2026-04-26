<?php

namespace Drupal\common\Storage;

/**
 * Stub for Drupal\common\Storage\DatabaseTableInterface.
 */
interface DatabaseTableInterface {

  public function getSchema(): array;

  public function getTableName(): string;

}
