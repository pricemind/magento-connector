<?php
/**
 * Unit tests for Sender
 */

namespace Stellion\Pricemind\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Stellion\Pricemind\Model\Sender;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

class SenderTest extends TestCase
{
    /** @var Sender */
    private $sender;

    /** @var MockObject|Curl */
    private $curlMock;

    /** @var MockObject|LoggerInterface */
    private $loggerMock;

    /** @var MockObject|JsonSerializer */
    private $jsonMock;

    protected function setUp(): void
    {
        $this->curlMock = $this->createMock(Curl::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->jsonMock = $this->createMock(JsonSerializer::class);

        $this->sender = new Sender(
            $this->curlMock,
            $this->loggerMock,
            $this->jsonMock
        );
    }

    public function testSendJsonWithEmptyUrl(): void
    {
        $result = $this->sender->sendJson('', ['test' => 'data']);

        $this->assertEquals(['ok' => true, 'status' => null, 'body' => null], $result);
    }

    public function testSendJsonPostSuccess(): void
    {
        $url = 'https://api.pricemind.io/v1/channels/123/prices';
        $payload = ['product_sku' => 'TEST-SKU', 'price' => '100.00'];
        $headers = ['X-API-Key' => 'test.1.secret'];
        $serializedPayload = '{"product_sku":"TEST-SKU","price":"100.00"}';

        $this->jsonMock->expects($this->once())
            ->method('serialize')
            ->with($payload)
            ->willReturn($serializedPayload);

        $this->curlMock->expects($this->once())
            ->method('setHeaders')
            ->with([
                'Content-Type' => 'application/json',
                'X-API-Key' => 'test.1.secret'
            ]);

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with($url, $serializedPayload);

        $this->curlMock->expects($this->once())
            ->method('getStatus')
            ->willReturn(201);

        $this->curlMock->expects($this->once())
            ->method('getBody')
            ->willReturn('{"success": true}');

        $result = $this->sender->sendJson($url, $payload, $headers);

        $this->assertEquals([
            'ok' => true,
            'status' => 201,
            'body' => '{"success": true}'
        ], $result);
    }

    public function testSendJsonPutMethod(): void
    {
        $url = 'https://api.pricemind.io/v1/custom-fields';
        $payload = ['channel_id' => 123, 'machine_name' => 'test_field'];
        $serializedPayload = '{"channel_id":123,"machine_name":"test_field"}';

        $this->jsonMock->method('serialize')->willReturn($serializedPayload);

        $this->curlMock->expects($this->once())
            ->method('setOption')
            ->with(CURLOPT_CUSTOMREQUEST, 'PUT');

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with($url, $serializedPayload);

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn('{"success": true}');

        $result = $this->sender->sendJson($url, $payload, [], 1, 2, 'PUT');

        $this->assertEquals([
            'ok' => true,
            'status' => 200,
            'body' => '{"success": true}'
        ], $result);
    }

    public function testSendJsonWithTimeouts(): void
    {
        $url = 'https://api.pricemind.io/v1/test';
        $payload = ['test' => 'data'];

        $this->jsonMock->method('serialize')->willReturn('{"test":"data"}');

        $this->curlMock->expects($this->once())
            ->method('setConnectTimeout')
            ->with(5);

        $this->curlMock->expects($this->once())
            ->method('setTimeout')
            ->with(10);

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn('{"success": true}');

        $result = $this->sender->sendJson($url, $payload, [], 5, 10);

        $this->assertTrue($result['ok']);
    }

    public function testSendJsonHttpError(): void
    {
        $url = 'https://api.pricemind.io/v1/test';
        $payload = ['test' => 'data'];

        $this->jsonMock->method('serialize')->willReturn('{"test":"data"}');

        $this->curlMock->method('getStatus')->willReturn(400);
        $this->curlMock->method('getBody')->willReturn('Bad Request');

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                '[Stellion_Pricemind] Non-2xx response',
                [
                    'status' => 400,
                    'body' => 'Bad Request'
                ]
            );

        $result = $this->sender->sendJson($url, $payload);

        $this->assertEquals([
            'ok' => false,
            'status' => 400,
            'body' => 'Bad Request'
        ], $result);
    }

    public function testSendJsonException(): void
    {
        $url = 'https://api.pricemind.io/v1/test';
        $payload = ['test' => 'data'];
        $exception = new \Exception('Connection failed');

        $this->jsonMock->method('serialize')->willReturn('{"test":"data"}');

        $this->curlMock->method('post')
            ->willThrowException($exception);

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                '[Stellion_Pricemind] Send failed',
                [
                    'error' => 'Connection failed',
                ]
            );

        $result = $this->sender->sendJson($url, $payload);

        $this->assertEquals([
            'ok' => false,
            'status' => null,
            'body' => 'Connection failed'
        ], $result);
    }

    public function testSendJsonWithoutTimeoutMethods(): void
    {
        // Test case where curl client doesn't have timeout methods
        $curlMockWithoutMethods = $this->createMock(Curl::class);
        $curlMockWithoutMethods->method('getStatus')->willReturn(200);
        $curlMockWithoutMethods->method('getBody')->willReturn('{"success": true}');

        $sender = new Sender($curlMockWithoutMethods, $this->loggerMock, $this->jsonMock);

        $this->jsonMock->method('serialize')->willReturn('{"test":"data"}');

        // Should not call timeout methods if they don't exist
        $curlMockWithoutMethods->expects($this->never())->method('setConnectTimeout');
        $curlMockWithoutMethods->expects($this->never())->method('setTimeout');

        $result = $sender->sendJson('https://api.test.com', ['test' => 'data']);

        $this->assertTrue($result['ok']);
    }

    /**
     * @dataProvider httpStatusProvider
     */
    public function testSendJsonStatusCodes(int $status, bool $expectedOk): void
    {
        $url = 'https://api.pricemind.io/v1/test';
        $payload = ['test' => 'data'];

        $this->jsonMock->method('serialize')->willReturn('{"test":"data"}');
        $this->curlMock->method('getStatus')->willReturn($status);
        $this->curlMock->method('getBody')->willReturn('Response body');

        if (!$expectedOk) {
            $this->loggerMock->expects($this->once())
                ->method('warning')
                ->with('[Stellion_Pricemind] Non-2xx response');
        }

        $result = $this->sender->sendJson($url, $payload);

        $this->assertEquals($expectedOk, $result['ok']);
        $this->assertEquals($status, $result['status']);
    }

    public function httpStatusProvider(): array
    {
        return [
            'Success 200' => [200, true],
            'Success 201' => [201, true],
            'Success 204' => [204, true],
            'Success 299' => [299, true],
            'Client Error 400' => [400, false],
            'Client Error 401' => [401, false],
            'Client Error 404' => [404, false],
            'Server Error 500' => [500, false],
            'Success 199' => [199, false], // Edge case: below 200
            'Success 300' => [300, false], // Edge case: at 300
        ];
    }
}
