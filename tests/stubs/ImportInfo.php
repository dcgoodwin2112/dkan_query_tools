<?php

namespace Drupal\dkan_datastore\Service\Info;

/**
 * Stub for Drupal\dkan_datastore\Service\Info\ImportInfo.
 */
class ImportInfo {

  public function getItem(string $identifier, string $version): object {
    return (object) [
      'fileFetcherStatus' => 'waiting',
      'importerStatus' => 'waiting',
      'importerError' => NULL,
    ];
  }

}
