<?php

namespace Drupal\dkan_query_tools\Tool;

/**
 * Tools for DKAN catalog search operations.
 */
class SearchTools {

  /**
   * @param \Closure $searchFactory
   *   Lazy factory returning the dkan.metastore_search.service. Injected as a
   *   service closure so the search service (which loads the 'dkan' search
   *   index on construction) is only built when a search actually runs, not at
   *   container-build / tool-instantiation time.
   */
  public function __construct(
    protected \Closure $searchFactory,
  ) {}

  /**
   * Search datasets by keyword via the DKAN search service.
   *
   * Calls the metastore search service in-process rather than issuing an HTTP
   * request to the site's own /api/1/search endpoint. The in-process call avoids
   * a self-directed round trip and, critically, never derives an outbound URL
   * from the request Host header (which would be a request-controlled SSRF
   * vector when trusted_host_patterns is unset or permissive).
   *
   * @param string $keyword
   *   Search term.
   * @param int $page
   *   Page number (1-based).
   * @param int $pageSize
   *   Results per page.
   */
  public function searchDatasets(string $keyword, int $page = 1, int $pageSize = 10): array {
    $pageSize = min(max($pageSize, 1), 50);
    $page = max($page, 1);

    try {
      $search = ($this->searchFactory)();
      $response = $search->search([
        'fulltext' => $keyword,
        'page' => $page,
        'page-size' => $pageSize,
      ]);

      $results = [];
      foreach ($this->responseValue($response, 'results', []) as $dataset) {
        $data = (array) $dataset;
        $results[] = [
          'identifier' => $data['identifier'] ?? NULL,
          'title' => $data['title'] ?? NULL,
          'description' => isset($data['description']) && is_scalar($data['description'])
            ? mb_substr((string) $data['description'], 0, 200)
            : NULL,
          'distributions' => isset($data['distribution']) ? count((array) $data['distribution']) : 0,
        ];
      }

      return [
        'results' => $results,
        'total' => (int) $this->responseValue($response, 'total', 0),
        'page' => $page,
        'page_size' => $pageSize,
      ];
    }
    catch (\Exception $e) {
      return ['error' => 'Search failed: ' . $e->getMessage()];
    }
  }

  /**
   * Read a key from the search response, which may be an object or an array.
   */
  private function responseValue(mixed $response, string $key, mixed $default): mixed {
    if (is_object($response)) {
      return $response->{$key} ?? $default;
    }
    if (is_array($response)) {
      return $response[$key] ?? $default;
    }
    return $default;
  }

}
