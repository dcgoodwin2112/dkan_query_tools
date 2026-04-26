<?php

namespace Drupal\Tests\dkan_query_tools\Unit\Tool;

use Drupal\dkan_query_tools\Tool\SearchTools;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class SearchToolsTest extends TestCase {

  protected function createTools(ClientInterface $client, ?SymfonyRequest $request = NULL): SearchTools {
    $requestStack = $this->createMock(RequestStack::class);
    $requestStack->method('getCurrentRequest')->willReturn($request);
    return new SearchTools($client, $requestStack);
  }

  public function testSearchDatasetsSuccess(): void {
    $longDesc = str_repeat('A', 300);
    $body = json_encode([
      'results' => [
        [
          'identifier' => 'abc-123',
          'title' => 'Test Dataset',
          'description' => $longDesc,
          'distribution' => [
            ['downloadURL' => 'http://example.com/a.csv'],
            ['downloadURL' => 'http://example.com/b.csv'],
          ],
          'keyword' => ['test', 'data'],
          '%Ref:distribution' => ['extra' => 'data'],
        ],
      ],
      'total' => 1,
    ]);

    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturn(new Response(200, [], $body));

    $request = SymfonyRequest::create('http://example.com');
    $tools = $this->createTools($client, $request);
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
    $body = json_encode([
      'results' => [],
      'total' => '42',
    ]);

    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturn(new Response(200, [], $body));

    $request = SymfonyRequest::create('http://example.com');
    $tools = $this->createTools($client, $request);
    $result = $tools->searchDatasets('test');

    $this->assertSame(42, $result['total']);
  }

  public function testSearchDatasetsHttpError(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willThrowException(
      new RequestException('Connection failed', new Request('GET', '/api/1/search'))
    );

    $request = SymfonyRequest::create('http://example.com');
    $tools = $this->createTools($client, $request);
    $result = $tools->searchDatasets('test');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Connection failed', $result['error']);
  }

  public function testSearchDatasetsPageSizeClamping(): void {
    $body = json_encode(['results' => [], 'total' => 0]);

    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        $this->anything(),
        $this->callback(function ($options) {
          return $options['query']['page-size'] === 50
            && $options['query']['page'] === 1;
        })
      )
      ->willReturn(new Response(200, [], $body));

    $request = SymfonyRequest::create('http://example.com');
    $tools = $this->createTools($client, $request);
    $result = $tools->searchDatasets('test', 0, 200);

    $this->assertEquals(1, $result['page']);
    $this->assertEquals(50, $result['page_size']);
  }

  public function testSearchDatasetsDrushFallbackUrl(): void {
    $body = json_encode(['results' => [], 'total' => 0]);

    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        $this->stringContains('http://localhost/api/1/search'),
        $this->anything(),
      )
      ->willReturn(new Response(200, [], $body));

    // No Symfony request (Drush context), no DRUSH_OPTIONS_URI.
    $tools = $this->createTools($client);
    $result = $tools->searchDatasets('test');

    $this->assertArrayHasKey('results', $result);
  }

}
