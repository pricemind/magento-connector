<?php
/**
 * Unit tests for API Client
 */

namespace Stellion\Pricemind\Test\Unit\Model\Api;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Stellion\Pricemind\Model\Api\Client;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\RequestInterface;
use Psr\Log\LoggerInterface;

class ClientTest extends TestCase
{
    /** @var Client */
    private $client;

    /** @var MockObject|ScopeConfigInterface */
    private $scopeConfigMock;

    /** @var MockObject|Curl */
    private $curlMock;

    /** @var MockObject|JsonSerializer */
    private $jsonMock;

    /** @var MockObject|RequestInterface */
    private $requestMock;

    /** @var MockObject|LoggerInterface */
    private $loggerMock;

    /** @var MockObject|EncryptorInterface */
    private $encryptorMock;

    protected function setUp(): void
    {
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->curlMock = $this->createMock(Curl::class);
        $this->jsonMock = $this->createMock(JsonSerializer::class);
        $this->requestMock = $this->createMock(RequestInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->encryptorMock = $this->createMock(EncryptorInterface::class);

        $this->client = new Client(
            $this->scopeConfigMock,
            $this->curlMock,
            $this->jsonMock,
            $this->requestMock,
            $this->loggerMock,
            $this->encryptorMock
        );
    }

    public function testGetBaseUrl(): void
    {
        $this->scopeConfigMock->expects($this->once())
            ->method('getValue')
            ->with('stellion_pricemind/api/base_url')
            ->willReturn('https://api.pricemind.io/');

        $result = $this->client->getBaseUrl();

        $this->assertEquals('https://api.pricemind.io', $result);
    }

    public function testGetBaseUrlWithWebsiteCode(): void
    {
        $this->scopeConfigMock->expects($this->once())
            ->method('getValue')
            ->with(
                'stellion_pricemind/api/base_url',
                \Magento\Store\Model\ScopeInterface::SCOPE_WEBSITE,
                'test_website'
            )
            ->willReturn('https://test.pricemind.io/');

        $result = $this->client->getBaseUrl('test_website');

        $this->assertEquals('https://test.pricemind.io', $result);
    }

    public function testGetApiKeySuccess(): void
    {
        $encryptedKey = 'encrypted_api_key';
        $decryptedKey = 'test.1.secret';

        $this->scopeConfigMock->expects($this->once())
            ->method('getValue')
            ->with('stellion_pricemind/api/access_key')
            ->willReturn($encryptedKey);

        $this->encryptorMock->expects($this->once())
            ->method('decrypt')
            ->with($encryptedKey)
            ->willReturn($decryptedKey);

        $result = $this->client->getApiKey();

        $this->assertEquals($decryptedKey, $result);
    }

    public function testGetApiKeyEmpty(): void
    {
        $this->scopeConfigMock->expects($this->once())
            ->method('getValue')
            ->with('stellion_pricemind/api/access_key')
            ->willReturn('');

        $result = $this->client->getApiKey();

        $this->assertEquals('', $result);
    }

    public function testGetApiKeyDecryptionFails(): void
    {
        $encryptedKey = 'encrypted_api_key';

        $this->scopeConfigMock->expects($this->once())
            ->method('getValue')
            ->with('stellion_pricemind/api/access_key')
            ->willReturn($encryptedKey);

        $this->encryptorMock->expects($this->once())
            ->method('decrypt')
            ->with($encryptedKey)
            ->willThrowException(new \Exception('Decryption failed'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to decrypt API key'));

        $result = $this->client->getApiKey();

        $this->assertEquals('', $result);
    }

    public function testListChannelsSuccess(): void
    {
        $apiKey = 'test.1.secret';
        $baseUrl = 'https://api.pricemind.io';
        $responseBody = '{"data": [{"id": 1, "name": "Test Channel"}]}';
        $expectedChannels = [['id' => 1, 'name' => 'Test Channel']];

        // Mock getApiKey
        $this->scopeConfigMock->method('getValue')
            ->willReturnMap([
                ['stellion_pricemind/api/access_key', null, null, 'encrypted_key'],
                ['stellion_pricemind/api/base_url', null, null, $baseUrl]
            ]);
        
        $this->encryptorMock->method('decrypt')->willReturn($apiKey);

        // Mock HTTP request
        $this->curlMock->expects($this->once())
            ->method('setHeaders')
            ->with([
                'Content-Type' => 'application/json',
                'X-API-Key' => $apiKey,
            ]);

        $this->curlMock->expects($this->once())
            ->method('setTimeout')
            ->with(10);

        $this->curlMock->expects($this->once())
            ->method('get')
            ->with($baseUrl . '/v1/channels');

        $this->curlMock->expects($this->once())
            ->method('getStatus')
            ->willReturn(200);

        $this->curlMock->expects($this->once())
            ->method('getBody')
            ->willReturn($responseBody);

        $this->jsonMock->expects($this->once())
            ->method('unserialize')
            ->with($responseBody)
            ->willReturn(['data' => $expectedChannels]);

        $result = $this->client->listChannels();

        $this->assertEquals($expectedChannels, $result);
    }

    public function testListChannelsNoApiKey(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->willReturn('');

        $result = $this->client->listChannels();

        $this->assertEquals([], $result);
    }

    public function testListChannelsHttpError(): void
    {
        $apiKey = 'test.1.secret';
        $baseUrl = 'https://api.pricemind.io';

        // Mock getApiKey and getBaseUrl
        $this->scopeConfigMock->method('getValue')
            ->willReturnMap([
                ['stellion_pricemind/api/access_key', null, null, 'encrypted_key'],
                ['stellion_pricemind/api/base_url', null, null, $baseUrl]
            ]);
        
        $this->encryptorMock->method('decrypt')->willReturn($apiKey);

        // Mock HTTP error
        $this->curlMock->method('getStatus')->willReturn(401);
        $this->curlMock->method('getBody')->willReturn('Unauthorized');

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                '[Pricemind] Non-2xx fetching channels',
                ['status' => 401, 'body' => 'Unauthorized']
            );

        $result = $this->client->listChannels();

        $this->assertEquals([], $result);
    }

    public function testGetActiveChannelSourceSuccess(): void
    {
        $channelId = '123';
        $apiKey = 'test.1.secret';
        $baseUrl = 'https://api.pricemind.io';
        $responseBody = '{"data": {"type": "magento", "title": "Test Store"}}';
        $expectedSource = ['type' => 'magento', 'title' => 'Test Store'];

        // Mock configuration
        $this->scopeConfigMock->method('getValue')
            ->willReturnMap([
                ['stellion_pricemind/api/access_key', null, null, 'encrypted_key'],
                ['stellion_pricemind/api/base_url', null, null, $baseUrl]
            ]);
        
        $this->encryptorMock->method('decrypt')->willReturn($apiKey);

        // Mock HTTP request
        $this->curlMock->expects($this->once())
            ->method('get')
            ->with($baseUrl . '/v1/channels/123/sources/active');

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn($responseBody);

        $this->jsonMock->expects($this->once())
            ->method('unserialize')
            ->with($responseBody)
            ->willReturn(['data' => $expectedSource]);

        $result = $this->client->getActiveChannelSource($channelId);

        $this->assertEquals($expectedSource, $result);
    }

    public function testLookupProductDomainIdBySkuSuccess(): void
    {
        $channelId = '123';
        $sku = 'TEST-SKU';
        $apiKey = 'test.1.secret';
        $baseUrl = 'https://api.pricemind.io';
        $responseBody = '{"data": {"product_domain_id": 456}}';

        // Mock configuration
        $this->scopeConfigMock->method('getValue')
            ->willReturnMap([
                ['stellion_pricemind/api/access_key', null, null, 'encrypted_key'],
                ['stellion_pricemind/api/base_url', null, null, $baseUrl]
            ]);
        
        $this->encryptorMock->method('decrypt')->willReturn($apiKey);

        // Mock HTTP request
        $this->curlMock->expects($this->once())
            ->method('get')
            ->with($baseUrl . '/v1/channels/123/product-domain?sku=TEST-SKU');

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn($responseBody);

        $this->jsonMock->expects($this->once())
            ->method('unserialize')
            ->with($responseBody)
            ->willReturn(['data' => ['product_domain_id' => 456]]);

        $result = $this->client->lookupProductDomainIdBySKU($channelId, $sku);

        $this->assertEquals(456, $result);
    }

    public function testLookupProductDomainIdBySkuNotFound(): void
    {
        $channelId = '123';
        $sku = 'TEST-SKU';
        $apiKey = 'test.1.secret';
        $baseUrl = 'https://api.pricemind.io';

        // Mock configuration
        $this->scopeConfigMock->method('getValue')
            ->willReturnMap([
                ['stellion_pricemind/api/access_key', null, null, 'encrypted_key'],
                ['stellion_pricemind/api/base_url', null, null, $baseUrl]
            ]);
        
        $this->encryptorMock->method('decrypt')->willReturn($apiKey);

        // Mock 404 response
        $this->curlMock->method('getStatus')->willReturn(404);
        $this->curlMock->method('getBody')->willReturn('Not Found');

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                '[Pricemind] Non-2xx product domain lookup',
                ['status' => 404, 'body' => 'Not Found']
            );

        $result = $this->client->lookupProductDomainIdBySKU($channelId, $sku);

        $this->assertNull($result);
    }
}
