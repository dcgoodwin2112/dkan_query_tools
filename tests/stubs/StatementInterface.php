<?php

namespace Drupal\Core\Database;

/**
 * Stub for Drupal\Core\Database\StatementInterface.
 */
interface StatementInterface extends \IteratorAggregate {

  public function fetchField(int $index = 0): mixed;

  public function fetchAssoc(): array|false;

  public function fetchAll(?int $mode = NULL): array;

  public function fetchCol(int $index = 0): array;

  public function getIterator(): \ArrayIterator;

}
