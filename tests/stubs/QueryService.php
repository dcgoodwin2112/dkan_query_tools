<?php

namespace Drupal\datastore\Service;

use RootedData\RootedJsonData;

/**
 * Stub for Drupal\datastore\Service\Query.
 */
class Query {

  public function runQuery(DatastoreQuery $datastoreQuery) {
    return new RootedJsonData('{"results":[],"count":0,"schema":{}}');
  }

}
