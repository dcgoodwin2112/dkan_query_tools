<?php

namespace Drupal\Tests\dkan_query_tools\Unit\Tool;

use Drupal\dkan_metastore_search\Search;
use Drupal\dkan_query_tools\Tool\SearchTools;
use PHPUnit\Framework\TestCase;

class SearchToolsTest extends TestCase {

  protected function createTools(Search $search): SearchTools {
    return new SearchTools(fn() => $search);
  }

  public function testSearchDatasetsSuccess(): void {
    $longDesc = str_repeat('A', 300);
    $response = (object) [
      'results' => [
        (object) [
          'identifier' => 'abc-123',
          'title' => 'Test Dataset',
          'description' => $longDesc,
          'distribution' => [
            (object) ['downloadURL' => 'http://example.com/a.csv'],
            (object) ['downloadURL' => 'http://example.com/b.csv'],
          ],
          'keyword' => ['test', 'data'],
          '%Ref:distribution' => ['extra' => 'data'],
        ],
      ],
      'total' => 1,
    ];

    $search = $this->createMock(Search::class);
    $search->method('search')->willReturn($response);

    $tools = $this->createTools($search);
    $result = $tools->searchDatasets('test');

    $this->assertCount(1, $result['results']);
    $this->assertSame(1, $result['total']);
    $this->assertEquals(1, $result['page']);
    $this->assertEquals(10, $result['page_size']);

    $item = $result['results'][0];
    $this->assertEquals('abc-123', $item['identifier']);
    $this->assertEquals('Test Dataset', $item['title']);
    $this->assertEquals(200, mb_strlen($item['description']));
    $this->assertEquals(2, $item['distributions']);
    // Only normalized keys should be present.
    $this->assertArrayNotHasKey('keyword', $item);
    $this->assertArrayNotHasKey('%Ref:distribution', $item);
  }

  public function testSearchDatasetsTotalCastToInt(): void {
    $search = $this->createMock(Search::class);
    $search->method('search')->willReturn((object) ['results' => [], 'total' => '42']);

    $tools = $this->createTools($search);
    $result = $tools->searchDatasets('test');

    $this->assertSame(42, $result['total']);
  }

  public function testSearchDatasetsServiceError(): void {
    $search = $this->createMock(Search::class);
    $search->method('search')->willThrowException(new \RuntimeException('index unavailable'));

    $tools = $this->createTools($search);
    $result = $tools->searchDatasets('test');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('index unavailable', $result['error']);
  }

  public function testSearchDatasetsClampsAndForwardsParams(): void {
    $search = $this->createMock(Search::class);
    $search->expects($this->once())
      ->method('search')
      ->with($this->callback(function ($params) {
        return $params['fulltext'] === 'test'
          && $params['page-size'] === 50
          && $params['page'] === 1;
      }))
      ->willReturn((object) ['results' => [], 'total' => 0]);

    $tools = $this->createTools($search);
    $result = $tools->searchDatasets('test', 0, 200);

    $this->assertEquals(1, $result['page']);
    $this->assertEquals(50, $result['page_size']);
  }

  public function testSearchDatasetsHandlesArrayResponse(): void {
    // The service contract returns an object, but tolerate an array shape too.
    $search = $this->createMock(Search::class);
    $search->method('search')->willReturn([
      'results' => [['identifier' => 'x-1', 'title' => 'X']],
      'total' => 1,
    ]);

    $tools = $this->createTools($search);
    $result = $tools->searchDatasets('x');

    $this->assertSame(1, $result['total']);
    $this->assertSame('x-1', $result['results'][0]['identifier']);
    $this->assertSame(0, $result['results'][0]['distributions']);
  }

}
