<?php
/**
 * Unit tests for ProductPriceChangeObserver
 */

namespace Stellion\Pricemind\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Stellion\Pricemind\Observer\ProductPriceChangeObserver;
use Stellion\Pricemind\Model\Sender;
use Stellion\Pricemind\Model\Api\Client as ApiClient;
use Stellion\Pricemind\Model\FailedRequestFactory;
use Stellion\Pricemind\Model\ResourceModel\FailedRequest as FailedRequestResource;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event;
use Magento\Catalog\Model\Product;
use Magento\Store\Model\Store;
use Magento\Store\Model\Website;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

class ProductPriceChangeObserverTest extends TestCase
{
    /** @var ProductPriceChangeObserver */
    private $observer;

    /** @var MockObject|Sender */
    private $senderMock;

    /** @var MockObject|LoggerInterface */
    private $loggerMock;

    /** @var MockObject|ScopeConfigInterface */
    private $scopeConfigMock;

    /** @var MockObject|StoreManagerInterface */
    private $storeManagerMock;

    /** @var MockObject|ApiClient */
    private $apiClientMock;

    /** @var MockObject|FailedRequestFactory */
    private $failedRequestFactoryMock;

    /** @var MockObject|FailedRequestResource */
    private $failedRequestResourceMock;

    /** @var MockObject|Observer */
    private $eventObserverMock;

    /** @var MockObject|Product */
    private $productMock;

    /** @var MockObject|Store */
    private $storeMock;

    /** @var MockObject|Website */
    private $websiteMock;

    protected function setUp(): void
    {
        $this->senderMock = $this->createMock(Sender::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->apiClientMock = $this->createMock(ApiClient::class);
        $this->failedRequestFactoryMock = $this->createMock(FailedRequestFactory::class);
        $this->failedRequestResourceMock = $this->createMock(FailedRequestResource::class);

        $this->observer = new ProductPriceChangeObserver(
            $this->senderMock,
            $this->loggerMock,
            $this->scopeConfigMock,
            $this->storeManagerMock,
            $this->apiClientMock,
            $this->failedRequestFactoryMock,
            $this->failedRequestResourceMock
        );

        $this->setupMocks();
    }

    private function setupMocks(): void
    {
        $this->eventObserverMock = $this->createMock(Observer::class);
        $this->productMock = $this->createMock(Product::class);
        $this->storeMock = $this->createMock(Store::class);
        $this->websiteMock = $this->createMock(Website::class);

        $eventMock = $this->createMock(Event::class);
        $eventMock->method('getProduct')->willReturn($this->productMock);
        $this->eventObserverMock->method('getEvent')->willReturn($eventMock);

        $this->storeMock->method('getWebsite')->willReturn($this->websiteMock);
        $this->websiteMock->method('getCode')->willReturn('default');
        $this->storeMock->method('getBaseCurrencyCode')->willReturn('USD');

        $this->storeManagerMock->method('getStore')->willReturn($this->storeMock);
    }

    public function testExecuteWithNoPriceChange(): void
    {
        // Setup: No price changes
        $this->productMock->method('getOrigData')->willReturnMap([
            ['price', '100.00'],
            ['special_price', '90.00'],
            ['special_from_date', '2024-01-01'],
            ['special_to_date', '2024-01-31']
        ]);

        $this->productMock->method('getData')->willReturnMap([
            ['price', '100.00'],
            ['special_price', '90.00'],
            ['special_from_date', '2024-01-01'],
            ['special_to_date', '2024-01-31']
        ]);

        // Expect: No API calls should be made
        $this->senderMock->expects($this->never())->method('sendJson');

        $this->observer->execute($this->eventObserverMock);
    }

    public function testExecuteWithPriceChange(): void
    {
        // Setup: Price changed from 100.00 to 120.00
        $this->productMock->method('getOrigData')->willReturnMap([
            ['price', '100.00'],
            ['special_price', null],
            ['special_from_date', null],
            ['special_to_date', null]
        ]);

        $this->productMock->method('getData')->willReturnMap([
            ['price', '120.00'],
            ['special_price', null],
            ['special_from_date', null],
            ['special_to_date', null]
        ]);

        $this->productMock->method('getSku')->willReturn('TEST-SKU');
        $this->productMock->method('getStoreId')->willReturn(1);

        // Setup API configuration
        $this->apiClientMock->method('getBaseUrl')->willReturn('https://api.pricemind.io');
        $this->apiClientMock->method('getApiKey')->willReturn('test.1.secret');
        $this->scopeConfigMock->method('getValue')->willReturn('123');

        // Expect: API call should be made
        $expectedPayload = [
            'product_sku' => 'TEST-SKU',
            'price' => '120.00',
            'currency' => 'USD',
            'includes_tax' => true,
        ];

        $this->senderMock->expects($this->once())
            ->method('sendJson')
            ->with(
                'https://api.pricemind.io/v1/channels/123/prices',
                $expectedPayload,
                ['X-API-Key' => 'test.1.secret'],
                1,
                2
            )
            ->willReturn(['ok' => true, 'status' => 200, 'body' => '{"success": true}']);

        $this->observer->execute($this->eventObserverMock);
    }

    public function testExecuteWithSpecialPriceChange(): void
    {
        // Setup: Special price changed from null to 80.00
        $this->productMock->method('getOrigData')->willReturnMap([
            ['price', '100.00'],
            ['special_price', null],
            ['special_from_date', null],
            ['special_to_date', null]
        ]);

        $this->productMock->method('getData')->willReturnMap([
            ['price', '100.00'],
            ['special_price', '80.00'],
            ['special_from_date', null],
            ['special_to_date', null]
        ]);

        $this->productMock->method('getSku')->willReturn('TEST-SKU');
        $this->productMock->method('getStoreId')->willReturn(1);

        // Setup API configuration
        $this->apiClientMock->method('getBaseUrl')->willReturn('https://api.pricemind.io');
        $this->apiClientMock->method('getApiKey')->willReturn('test.1.secret');
        $this->scopeConfigMock->method('getValue')->willReturn('123');

        // Expect: API call should include special_price
        $expectedPayload = [
            'product_sku' => 'TEST-SKU',
            'price' => '100.00',
            'currency' => 'USD',
            'includes_tax' => true,
            'special_price' => '80.00',
        ];

        $this->senderMock->expects($this->once())
            ->method('sendJson')
            ->with(
                'https://api.pricemind.io/v1/channels/123/prices',
                $expectedPayload,
                ['X-API-Key' => 'test.1.secret'],
                1,
                2
            )
            ->willReturn(['ok' => true, 'status' => 200, 'body' => '{"success": true}']);

        $this->observer->execute($this->eventObserverMock);
    }

    public function testExecuteWithSpecialPriceDateChange(): void
    {
        // Setup: Special price dates changed
        $this->productMock->method('getOrigData')->willReturnMap([
            ['price', '100.00'],
            ['special_price', '80.00'],
            ['special_from_date', null],
            ['special_to_date', null]
        ]);

        $this->productMock->method('getData')->willReturnMap([
            ['price', '100.00'],
            ['special_price', '80.00'],
            ['special_from_date', '2024-01-01'],
            ['special_to_date', '2024-01-31']
        ]);

        $this->productMock->method('getSku')->willReturn('TEST-SKU');
        $this->productMock->method('getStoreId')->willReturn(1);

        // Setup API configuration
        $this->apiClientMock->method('getBaseUrl')->willReturn('https://api.pricemind.io');
        $this->apiClientMock->method('getApiKey')->willReturn('test.1.secret');
        $this->scopeConfigMock->method('getValue')->willReturn('123');

        // Expect: API calls for custom fields
        $this->senderMock->expects($this->exactly(2))
            ->method('sendJson')
            ->withConsecutive(
                // Custom field for start date
                [
                    'https://api.pricemind.io/v1/custom-fields',
                    [
                        'channel_id' => 123,
                        'machine_name' => 'special_price_start_date',
                        'product_sku' => 'TEST-SKU',
                        'value' => '2024-01-01',
                    ],
                    ['X-API-Key' => 'test.1.secret'],
                    1,
                    2,
                    'PUT'
                ],
                // Custom field for end date
                [
                    'https://api.pricemind.io/v1/custom-fields',
                    [
                        'channel_id' => 123,
                        'machine_name' => 'special_price_end_date',
                        'product_sku' => 'TEST-SKU',
                        'value' => '2024-01-31',
                    ],
                    ['X-API-Key' => 'test.1.secret'],
                    1,
                    2,
                    'PUT'
                ]
            )
            ->willReturn(['ok' => true, 'status' => 200, 'body' => '{"success": true}']);

        $this->observer->execute($this->eventObserverMock);
    }

    public function testExecuteWithMissingConfiguration(): void
    {
        // Setup: Price change but missing API configuration
        $this->productMock->method('getOrigData')->willReturn('100.00');
        $this->productMock->method('getData')->willReturn('120.00');
        $this->productMock->method('getStoreId')->willReturn(1);

        $this->apiClientMock->method('getBaseUrl')->willReturn('https://api.pricemind.io');
        $this->apiClientMock->method('getApiKey')->willReturn(''); // Empty API key
        $this->scopeConfigMock->method('getValue')->willReturn('');

        // Expect: No API calls should be made
        $this->senderMock->expects($this->never())->method('sendJson');

        $this->observer->execute($this->eventObserverMock);
    }

    public function testExecuteWithApiFailure(): void
    {
        // Setup: Price change with API failure
        $this->productMock->method('getOrigData')->willReturnMap([
            ['price', '100.00'],
            ['special_price', null],
            ['special_from_date', null],
            ['special_to_date', null]
        ]);

        $this->productMock->method('getData')->willReturnMap([
            ['price', '120.00'],
            ['special_price', null],
            ['special_from_date', null],
            ['special_to_date', null]
        ]);

        $this->productMock->method('getSku')->willReturn('TEST-SKU');
        $this->productMock->method('getStoreId')->willReturn(1);

        // Setup API configuration
        $this->apiClientMock->method('getBaseUrl')->willReturn('https://api.pricemind.io');
        $this->apiClientMock->method('getApiKey')->willReturn('test.1.secret');
        $this->scopeConfigMock->method('getValue')->willReturn('123');

        // Mock API failure
        $this->senderMock->method('sendJson')
            ->willReturn(['ok' => false, 'status' => 500, 'body' => 'Internal Server Error']);

        // Mock failed request factory and resource
        $failedRequestMock = $this->createMock(\Stellion\Pricemind\Model\FailedRequest::class);
        $this->failedRequestFactoryMock->method('create')->willReturn($failedRequestMock);
        
        $failedRequestMock->expects($this->once())->method('setData');
        $this->failedRequestResourceMock->expects($this->once())->method('save');

        $this->observer->execute($this->eventObserverMock);
    }
}
