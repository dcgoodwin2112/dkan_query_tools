<?php

namespace Drupal\Core\Database\Query;

use Drupal\Core\Database\StatementInterface;

/**
 * Stub for Drupal\Core\Database\Query\SelectInterface.
 */
interface SelectInterface {

  public function fields(string $table_alias, array $fields = []): SelectInterface;

  public function condition(string $field, $value = NULL, ?string $operator = NULL): SelectInterface;

  public function orderBy(string $field, string $direction = 'ASC'): SelectInterface;

  public function range(?int $start = NULL, ?int $length = NULL): SelectInterface;

  public function execute(): StatementInterface;

  public function countQuery(): SelectInterface;

  public function fetchField(int $index = 0): mixed;

  public function addExpression(string $expression, ?string $alias = NULL, array $arguments = []): SelectInterface;

  public function addField(string $table_alias, string $field, ?string $alias = NULL): SelectInterface;

  public function groupBy(string $field): SelectInterface;

}
