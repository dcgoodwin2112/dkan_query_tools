<?php

namespace Drupal\dkan_query_tools\Tool;

use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tools for DKAN catalog search operations.
 */
class SearchTools {

  public function __construct(
    protected ClientInterface $httpClient,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Search datasets by keyword via the DKAN search API.
   */
  public function searchDatasets(string $keyword, int $page = 1, int $pageSize = 10): array {
    $pageSize = min(max($pageSize, 1), 50);
    $page = max($page, 1);

    $baseUrl = $this->getBaseUrl();
    $url = $baseUrl . '/api/1/search';

    try {
      $response = $this->httpClient->request('GET', $url, [
        'query' => [
          'fulltext' => $keyword,
          'page' => $page,
          'page-size' => $pageSize,
        ],
        'timeout' => 10,
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);

      $results = [];
      foreach ($data['results'] ?? [] as $dataset) {
        $results[] = [
          'identifier' => $dataset['identifier'] ?? NULL,
          'title' => $dataset['title'] ?? NULL,
          'description' => isset($dataset['description']) ? mb_substr($dataset['description'], 0, 200) : NULL,
          'distributions' => isset($dataset['distribution']) ? count($dataset['distribution']) : 0,
        ];
      }

      return [
        'results' => $results,
        'total' => (int) ($data['total'] ?? 0),
        'page' => $page,
        'page_size' => $pageSize,
      ];
    }
    catch (\Exception $e) {
      return ['error' => 'Search failed: ' . $e->getMessage()];
    }
  }

  /**
   * Get the base URL for API requests.
   */
  protected function getBaseUrl(): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      return $request->getSchemeAndHttpHost();
    }

    // Drush context: use DRUSH_OPTIONS_URI or fallback.
    $uri = getenv('DRUSH_OPTIONS_URI');
    if ($uri) {
      return rtrim($uri, '/');
    }

    return 'http://localhost';
  }

}
