<?php

namespace Drupal\Core\Database;

use Drupal\Core\Database\Query\SelectInterface;

/**
 * Stub for Drupal\Core\Database\Connection.
 */
abstract class Connection {

  public function select(string $table, ?string $alias = NULL, array $options = []): SelectInterface {
    return new class implements SelectInterface {

      public function fields(string $table_alias, array $fields = []): SelectInterface {
        return $this;
      }

      public function condition(string $field, $value = NULL, ?string $operator = NULL): SelectInterface {
        return $this;
      }

      public function orderBy(string $field, string $direction = 'ASC'): SelectInterface {
        return $this;
      }

      public function range(?int $start = NULL, ?int $length = NULL): SelectInterface {
        return $this;
      }

      public function execute(): StatementInterface {
        return new class implements StatementInterface {

          public function fetchField(int $index = 0): mixed {
            return 0;
          }

          public function fetchAssoc(): array|false {
            return [];
          }

          public function getIterator(): \ArrayIterator {
            return new \ArrayIterator([]);
          }

        };
      }

      public function countQuery(): SelectInterface {
        return $this;
      }

      public function fetchField(int $index = 0): mixed {
        return 0;
      }

      public function addExpression(string $expression, ?string $alias = NULL, array $arguments = []): SelectInterface {
        return $this;
      }

      public function addField(string $table_alias, string $field, ?string $alias = NULL): SelectInterface {
        return $this;
      }

      public function groupBy(string $field): SelectInterface {
        return $this;
      }

    };
  }

  public function schema(): Schema {
    return new Schema();
  }

}
