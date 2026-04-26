<?php

namespace Drupal\datastore\Service;

/**
 * Stub for Drupal\datastore\Service\DatastoreQuery.
 *
 * The real class extends RootedJsonData, but for unit testing purposes
 * we use a simple stub that stores the JSON without validation.
 */
class DatastoreQuery {

  protected string $json;
  protected $rowsLimit;

  public function __construct(string $json, $rows_limit = NULL) {
    $this->json = $json;
    $this->rowsLimit = $rows_limit;
  }

  public function __toString(): string {
    return $this->json;
  }

}
