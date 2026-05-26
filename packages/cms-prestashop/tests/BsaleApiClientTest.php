<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios para BsaleApiClient.
 * Prueba la paginación automática de getAll() usando un doble de test.
 */
class BsaleApiClientTest extends TestCase
{
    // ── Paginación ────────────────────────────────────────────────────────────

    public function test_getAll_returns_single_page_when_count_less_than_limit(): void
    {
        $client = $this->createPartialMock(BsaleApiClient::class, ['get']);
        $client->method('get')->willReturn([
            'items' => [
                ['id' => 1, 'code' => 'SKU-001'],
                ['id' => 2, 'code' => 'SKU-002'],
            ],
        ]);

        $results = $client->getAll('/v1/products.json');

        $this->assertCount(2, $results);
        $this->assertEquals('SKU-001', $results[0]['code']);
    }

    public function test_getAll_auto_paginates_until_page_is_incomplete(): void
    {
        $page1 = array_fill(0, 50, ['id' => 1, 'code' => 'SKU']);
        $page2 = [['id' => 51, 'code' => 'SKU-LAST']]; // < 50 items → última página

        $client = $this->createPartialMock(BsaleApiClient::class, ['get']);
        $client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                ['items' => $page1],
                ['items' => $page2]
            );

        $results = $client->getAll('/v1/products.json');

        $this->assertCount(51, $results);
    }

    public function test_getAll_returns_empty_array_on_empty_response(): void
    {
        $client = $this->createPartialMock(BsaleApiClient::class, ['get']);
        $client->method('get')->willReturn(['items' => []]);

        $results = $client->getAll('/v1/products.json');

        $this->assertSame([], $results);
    }

    public function test_getAll_stops_after_exactly_three_full_pages(): void
    {
        $fullPage  = array_fill(0, 50, ['id' => 1]);
        $emptyPage = ['items' => []];

        $client = $this->createPartialMock(BsaleApiClient::class, ['get']);
        $client->expects($this->exactly(4))  // 3 full + 1 empty
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                ['items' => $fullPage],
                ['items' => $fullPage],
                ['items' => $fullPage],
                $emptyPage
            );

        $results = $client->getAll('/v1/products.json');

        $this->assertCount(150, $results);
    }

    // ── Excepciones ───────────────────────────────────────────────────────────

    public function test_BsaleApiException_4xx_is_client_error(): void
    {
        $exception = new BsaleApiException(404, '{"message":"Not found"}');
        $this->assertEquals(404, $exception->getCode());
        $this->assertTrue($exception->isClientError());
        $this->assertFalse($exception->isServerError());
        $this->assertStringContainsString('404', $exception->getMessage());
    }

    public function test_BsaleApiException_identifies_server_errors(): void
    {
        $exception = new BsaleApiException(503, 'Service unavailable');
        $this->assertTrue($exception->isServerError());
        $this->assertFalse($exception->isClientError());
    }

    public function test_BsaleApiException_message_includes_http_code(): void
    {
        $exception = new BsaleApiException(422, 'Validation error');
        $this->assertStringContainsString('422', $exception->getMessage());
    }
}
