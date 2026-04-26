<?php

namespace RootedData;

/**
 * Stub for RootedData\RootedJsonData.
 */
class RootedJsonData {

  protected string $json;

  public function __construct(string $json = '{}', $schema = '{}') {
    $this->json = $json;
  }

  public function __toString(): string {
    return $this->json;
  }

  public function set(string $path, $value): void {
    if ($path === '$') {
      $this->json = json_encode($value);
    }
  }

}
