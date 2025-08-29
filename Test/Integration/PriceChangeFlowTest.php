<?php
/**
 * Integration tests for the complete price change flow
 */

namespace Stellion\Pricemind\Test\Integration;

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

class PriceChangeFlowTest extends TestCase
{
    /** @var ProductPriceChangeObserver */
    private $observer;

    /** @var MockObject|Sender */
    private $senderMock;

    /** @var MockObject|ApiClient */
    private $apiClientMock;

    /** @var MockObject|ScopeConfigInterface */
    private $scopeConfigMock;

    /** @var MockObject|StoreManagerInterface */
    private $storeManagerMock;

    /** @var MockObject|LoggerInterface */
    private $loggerMock;

    protected function setUp(): void
    {
        $this->senderMock = $this->createMock(Sender::class);
        $this->apiClientMock = $this->createMock(ApiClient::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $failedRequestFactoryMock = $this->createMock(FailedRequestFactory::class);
        $failedRequestResourceMock = $this->createMock(FailedRequestResource::class);

        $this->observer = new ProductPriceChangeObserver(
            $this->senderMock,
            $this->loggerMock,
            $this->scopeConfigMock,
            $this->storeManagerMock,
            $this->apiClientMock,
            $failedRequestFactoryMock,
            $failedRequestResourceMock
        );
    }

    public function testCompleteProductPriceUpdateFlow(): void
    {
        // Arrange: Create a complete product price update scenario
        $observer = $this->createProductPriceChangeEvent([
            'sku' => 'INTEGRATION-TEST-SKU',
            'store_id' => 1,
            'website_code' => 'default',
            'currency' => 'USD',
            'price_changes' => [
                'price' => ['old' => '100.00', 'new' => '150.00'],
                'special_price' => ['old' => null, 'new' => '120.00'],
                'special_from_date' => ['old' => null, 'new' => '2024-01-01'],
                'special_to_date' => ['old' => null, 'new' => '2024-01-31'],
            ]
        ]);

        // Configure API settings
        $this->apiClientMock->method('getBaseUrl')->willReturn('https://api.pricemind.io');
        $this->apiClientMock->method('getApiKey')->willReturn('test.1.integration-secret');
        $this->scopeConfigMock->method('getValue')->willReturn('999');

        // Expect multiple API calls
        $this->senderMock->expects($this->exactly(3))
            ->method('sendJson')
            ->withConsecutive(
                // 1. Price update call
                [
                    'https://api.pricemind.io/v1/channels/999/prices',
                    [
                        'product_sku' => 'INTEGRATION-TEST-SKU',
                        'price' => '150.00',
                        'currency' => 'USD',
                        'includes_tax' => true,
                        'special_price' => '120.00',
                    ],
                    ['X-API-Key' => 'test.1.integration-secret'],
                    1,
                    2
                ],
                // 2. Special price start date custom field
                [
                    'https://api.pricemind.io/v1/custom-fields',
                    [
                        'channel_id' => 999,
                        'machine_name' => 'special_price_start_date',
                        'product_sku' => 'INTEGRATION-TEST-SKU',
                        'value' => '2024-01-01',
                    ],
                    ['X-API-Key' => 'test.1.integration-secret'],
                    1,
                    2,
                    'PUT'
                ],
                // 3. Special price end date custom field
                [
                    'https://api.pricemind.io/v1/custom-fields',
                    [
                        'channel_id' => 999,
                        'machine_name' => 'special_price_end_date',
                        'product_sku' => 'INTEGRATION-TEST-SKU',
                        'value' => '2024-01-31',
                    ],
                    ['X-API-Key' => 'test.1.integration-secret'],
                    1,
                    2,
                    'PUT'
                ]
            )
            ->willReturn(['ok' => true, 'status' => 200, 'body' => '{"success": true}']);

        // Act: Execute the observer
        $this->observer->execute($observer);

        // Assert: Verify that all expected API calls were made
        // (Assertions are handled by the expects() calls above)
    }

    public function testPriceRemovalFlow(): void
    {
        // Test removing special price (setting to null)
        $observer = $this->createProductPriceChangeEvent([
            'sku' => 'REMOVE-SPECIAL-PRICE-SKU',
            'store_id' => 1,
            'website_code' => 'default',
            'currency' => 'EUR',
            'price_changes' => [
                'special_price' => ['old' => '80.00', 'new' => null],
            ]
        ]);

        $this->apiClientMock->method('getBaseUrl')->willReturn('https://api.pricemind.io');
        $this->apiClientMock->method('getApiKey')->willReturn('test.1.secret');
        $this->scopeConfigMock->method('getValue')->willReturn('777');

        $this->senderMock->expects($this->once())
            ->method('sendJson')
            ->with(
                'https://api.pricemind.io/v1/channels/777/prices',
                [
                    'product_sku' => 'REMOVE-SPECIAL-PRICE-SKU',
                    'price' => '100.00', // Assuming default price
                    'currency' => 'EUR',
                    'includes_tax' => true,
                    'special_price' => null, // Explicitly null to remove
                ],
                ['X-API-Key' => 'test.1.secret'],
                1,
                2
            )
            ->willReturn(['ok' => true, 'status' => 200, 'body' => '{"success": true}']);

        $this->observer->execute($observer);
    }

    public function testMultiStoreConfiguration(): void
    {
        // Test different configuration per website
        $observer = $this->createProductPriceChangeEvent([
            'sku' => 'MULTI-STORE-SKU',
            'store_id' => 2,
            'website_code' => 'eu_website',
            'currency' => 'EUR',
            'price_changes' => [
                'price' => ['old' => '100.00', 'new' => '110.00'],
            ]
        ]);

        // EU website has different API configuration
        $this->apiClientMock->method('getBaseUrl')
            ->with('eu_website')
            ->willReturn('https://eu.api.pricemind.io');
        
        $this->apiClientMock->method('getApiKey')
            ->with('eu_website')
            ->willReturn('eu.1.secret');
        
        $this->scopeConfigMock->method('getValue')
            ->willReturn('555'); // EU channel ID

        $this->senderMock->expects($this->once())
            ->method('sendJson')
            ->with(
                'https://eu.api.pricemind.io/v1/channels/555/prices',
                [
                    'product_sku' => 'MULTI-STORE-SKU',
                    'price' => '110.00',
                    'currency' => 'EUR',
                    'includes_tax' => true,
                ],
                ['X-API-Key' => 'eu.1.secret'],
                1,
                2
            )
            ->willReturn(['ok' => true, 'status' => 200, 'body' => '{"success": true}']);

        $this->observer->execute($observer);
    }

    /**
     * Helper method to create a mock product price change event
     */
    private function createProductPriceChangeEvent(array $config): Observer
    {
        $productMock = $this->createMock(Product::class);
        $storeMock = $this->createMock(Store::class);
        $websiteMock = $this->createMock(Website::class);
        $eventMock = $this->createMock(Event::class);
        $observerMock = $this->createMock(Observer::class);

        // Configure product mock
        $productMock->method('getSku')->willReturn($config['sku']);
        $productMock->method('getStoreId')->willReturn($config['store_id']);

        // Set up price changes
        $origDataMap = [];
        $dataMap = [];
        
        foreach ($config['price_changes'] as $field => $change) {
            $origDataMap[] = [$field, $change['old']];
            $dataMap[] = [$field, $change['new']];
        }
        
        // Add default price if not specified
        if (!isset($config['price_changes']['price'])) {
            $origDataMap[] = ['price', '100.00'];
            $dataMap[] = ['price', '100.00'];
        }

        $productMock->method('getOrigData')->willReturnMap($origDataMap);
        $productMock->method('getData')->willReturnMap($dataMap);

        // Configure store and website mocks
        $websiteMock->method('getCode')->willReturn($config['website_code']);
        $storeMock->method('getWebsite')->willReturn($websiteMock);
        $storeMock->method('getBaseCurrencyCode')->willReturn($config['currency']);

        $this->storeManagerMock->method('getStore')
            ->with($config['store_id'])
            ->willReturn($storeMock);

        // Configure event and observer mocks
        $eventMock->method('getProduct')->willReturn($productMock);
        $observerMock->method('getEvent')->willReturn($eventMock);

        return $observerMock;
    }
}
