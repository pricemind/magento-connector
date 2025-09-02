<?php

namespace Stellion\Pricemind\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Catalog\Model\Product;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Stellion\Pricemind\Model\Sender;
use Stellion\Pricemind\Model\Api\Client as ApiClient;
use Stellion\Pricemind\Model\FailedRequestFactory;
use Stellion\Pricemind\Model\ResourceModel\FailedRequest as FailedRequestResource;

class ProductPriceChangeObserver implements ObserverInterface
{
    /** @var Sender */
    private $sender;
    /** @var LoggerInterface */
    private $logger;
    /** @var ScopeConfigInterface */
    private $scopeConfig;
    /** @var StoreManagerInterface */
    private $storeManager;
    /** @var ApiClient */
    private $apiClient;
    /** @var FailedRequestFactory */
    private $failedRequestFactory;
    /** @var FailedRequestResource */
    private $failedRequestResource;

    public function __construct(
        Sender $sender,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        ApiClient $apiClient,
        FailedRequestFactory $failedRequestFactory,
        FailedRequestResource $failedRequestResource
    ) {
        $this->sender = $sender;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->apiClient = $apiClient;
        $this->failedRequestFactory = $failedRequestFactory;
        $this->failedRequestResource = $failedRequestResource;
    }

    public function execute(Observer $observer)
    {
        /** @var Product $product */
        $product = $observer->getEvent()->getProduct();
        
        $this->logger->info('[Stellion_Pricemind] Observer triggered for product ID: ' . $product->getId() . ', SKU: ' . $product->getSku());

        $origPrice = $product->getOrigData('price');
        $newPrice = $product->getData('price');
        $origSpecial = $product->getOrigData('special_price');
        $newSpecial = $product->getData('special_price');
        $origFrom = $product->getOrigData('special_from_date');
        $newFrom = $product->getData('special_from_date');
        $origTo = $product->getOrigData('special_to_date');
        $newTo = $product->getData('special_to_date');

        $priceChanged = $origPrice === null || (string)$origPrice !== (string)$newPrice;
        $specialChanged = (string)$origSpecial !== (string)$newSpecial;
        $fromChanged = (string)$origFrom !== (string)$newFrom;
        $toChanged = (string)$origTo !== (string)$newTo;
        
        // Check if we have any price-related changes to process
        $hasPriceChanges = $priceChanged || $specialChanged || $fromChanged || $toChanged;

        $store = $this->storeManager->getStore((int)$product->getStoreId());
        $websiteCode = (string)$store->getWebsite()->getCode();

        $baseUrl = $this->apiClient->getBaseUrl($websiteCode);
        $apiKey = $this->apiClient->getApiKey($websiteCode);
        $channelId = (string)$this->scopeConfig->getValue('stellion_pricemind/api/channel_id', ScopeInterface::SCOPE_WEBSITE, $websiteCode);

        if ($apiKey === '' || $channelId === '') {
            $this->logger->info('[Stellion_Pricemind] Skipping product ' . $product->getSku() . ' - not configured (apiKey: ' . ($apiKey === '' ? 'empty' : 'present') . ', channelId: ' . ($channelId === '' ? 'empty' : $channelId) . ')');
            return; // not configured
        }

        // Process price changes if any occurred
        if ($hasPriceChanges) {
            $this->logger->info('[Stellion_Pricemind] Processing price changes for product ' . $product->getSku());
            $this->processPriceChanges($product, $priceChanged, $specialChanged, $fromChanged, $toChanged, 
                                     $newPrice, $newSpecial, $newFrom, $newTo, $store, $baseUrl, $apiKey, $channelId);
        } else {
            $this->logger->info('[Stellion_Pricemind] No price changes detected for product ' . $product->getSku());
        }

        // Always process editable custom fields (they might have changed even if prices didn't)
        $this->logger->info('[Stellion_Pricemind] Processing editable custom fields for product ' . $product->getSku());
        $this->syncEditableCustomFields($product, $channelId, $websiteCode, $baseUrl, $apiKey);
    }

    /**
     * Process price-related changes
     */
    private function processPriceChanges(Product $product, bool $priceChanged, bool $specialChanged, 
                                       bool $fromChanged, bool $toChanged, $newPrice, $newSpecial, 
                                       $newFrom, $newTo, $store, string $baseUrl, string $apiKey, string $channelId): void
    {
        $endpoint = rtrim($baseUrl, '/') . '/v1/channels/' . rawurlencode($channelId) . '/prices';

        // Send price update if price changed
        if ($priceChanged) {
            $payload = [
                'product_sku' => (string)$product->getSku(),
                'price' => (string)$newPrice,
                'currency' => (string)$store->getBaseCurrencyCode(),
                'includes_tax' => true,
            ];

            // Include special_price when it changes. Send null to clear when removed.
            if ($specialChanged) {
                if ($newSpecial !== null && $newSpecial !== '' && (float)$newSpecial > 0) {
                    $payload['special_price'] = (string)$newSpecial;
                } else {
                    $payload['special_price'] = null;
                }
            }

            $result = $this->sender->sendJson($endpoint, $payload, [
                'X-API-Key' => $apiKey,
            ], 5, 15);

            if (!$result['ok']) {
                try {
                    $failed = $this->failedRequestFactory->create();
                    $failed->setData([
                        'endpoint' => $endpoint,
                        'method' => 'POST',
                        'headers' => json_encode(['X-API-Key' => '***']),
                        'payload' => json_encode($payload),
                        'error' => (string)$result['body'],
                        'retry_count' => 0,
                        'status' => 0,
                        'next_attempt_at' => null,
                    ]);
                    $this->failedRequestResource->save($failed);
                } catch (\Throwable $e) {
                    $this->logger->error('[Stellion_Pricemind] Failed to persist failed request: ' . $e->getMessage());
                }
            }
        }

        // Handle special_from/to -> custom fields mapping
        if ($fromChanged || $toChanged) {
            try {
                // Static machine names in Pricemind
                $specialFromField = 'special_price_start_date';
                $specialToField = 'special_price_end_date';
                $channelIdInt = (int)$channelId;
                $sku = (string)$product->getSku();

                // Send from
                if ($fromChanged) {
                    $payloadCF = [
                        'channel_id' => $channelIdInt,
                        'machine_name' => $specialFromField,
                        'product_sku' => $sku,
                        'value' => (string)$newFrom,
                    ];
                    $endpointCF = rtrim($baseUrl, '/') . '/v1/custom-fields';
                    $res = $this->sender->sendJson($endpointCF, $payloadCF, ['X-API-Key' => $apiKey], 5, 15, 'PUT');
                    if (!$res['ok']) {
                        try {
                            $failed = $this->failedRequestFactory->create();
                            $failed->setData([
                                'endpoint' => $endpointCF,
                                'method' => 'PUT',
                                'headers' => json_encode(['X-API-Key' => '***']),
                                'payload' => json_encode($payloadCF),
                                'error' => (string)$res['body'],
                                'retry_count' => 0,
                                'status' => 0,
                                'next_attempt_at' => null,
                            ]);
                            $this->failedRequestResource->save($failed);
                        } catch (\Throwable $e) {
                            $this->logger->error('[Stellion_Pricemind] Failed to persist failed request: ' . $e->getMessage());
                        }
                    }
                }
                // Send to
                if ($toChanged) {
                    $payloadCF = [
                        'channel_id' => $channelIdInt,
                        'machine_name' => $specialToField,
                        'product_sku' => $sku,
                        'value' => (string)$newTo,
                    ];
                    $endpointCF = rtrim($baseUrl, '/') . '/v1/custom-fields';
                    $res = $this->sender->sendJson($endpointCF, $payloadCF, ['X-API-Key' => $apiKey], 5, 15, 'PUT');
                    if (!$res['ok']) {
                        try {
                            $failed = $this->failedRequestFactory->create();
                            $failed->setData([
                                'endpoint' => $endpointCF,
                                'method' => 'PUT',
                                'headers' => json_encode(['X-API-Key' => '***']),
                                'payload' => json_encode($payloadCF),
                                'error' => (string)$res['body'],
                                'retry_count' => 0,
                                'status' => 0,
                                'next_attempt_at' => null,
                            ]);
                            $this->failedRequestResource->save($failed);
                        } catch (\Throwable $e) {
                            $this->logger->error('[Stellion_Pricemind] Failed to persist failed request: ' . $e->getMessage());
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[Stellion_Pricemind] Failed to update special date custom fields: ' . $e->getMessage());
            }
        }
    }

    /**
     * Sync editable custom fields based on channel integration configuration
     */
    private function syncEditableCustomFields(Product $product, string $channelId, string $websiteCode, string $baseUrl, string $apiKey): void
    {
        try {
            // Get channel integration config
            $this->logger->info('[Stellion_Pricemind] Fetching channel integration config for channel: ' . $channelId);
            $channelIntegration = $this->apiClient->getChannelIntegration($channelId, $websiteCode);
            if (!$channelIntegration || !isset($channelIntegration['config'])) {
                $this->logger->info('[Stellion_Pricemind] No integration config found for channel: ' . $channelId);
                return; // No integration config found
            }

            $config = $channelIntegration['config'];
            
            // Extract editable_custom_fields from Magento config
            $editableFields = [];
            if (isset($config['magento']['editable_custom_fields']) && is_array($config['magento']['editable_custom_fields'])) {
                $editableFields = $config['magento']['editable_custom_fields'];
            }

            $this->logger->info('[Stellion_Pricemind] Found editable fields configuration: ' . json_encode($editableFields));

            if (empty($editableFields)) {
                $this->logger->info('[Stellion_Pricemind] No editable fields configured for channel: ' . $channelId);
                return; // No editable fields configured
            }

            $channelIdInt = (int)$channelId;
            $sku = (string)$product->getSku();
            $endpointCF = rtrim($baseUrl, '/') . '/v1/custom-fields';

            // Send each editable custom field
            foreach ($editableFields as $fieldName) {
                if (!is_string($fieldName) || $fieldName === '') {
                    $this->logger->info('[Stellion_Pricemind] Skipping invalid field name: ' . json_encode($fieldName));
                    continue;
                }

                // Get current value from product
                $value = $this->getProductAttributeValue($product, $fieldName);
                $this->logger->info('[Stellion_Pricemind] Field "' . $fieldName . '" value for product ' . $product->getSku() . ': ' . ($value === null ? 'null' : '"' . $value . '"'));
                if ($value === null) {
                    continue; // Skip if attribute doesn't exist or has no value
                }

                $payloadCF = [
                    'channel_id' => $channelIdInt,
                    'machine_name' => $fieldName,
                    'product_sku' => $sku,
                    'value' => (string)$value,
                ];

                $this->logger->info('[Stellion_Pricemind] Sending custom field update: ' . json_encode($payloadCF));
                $res = $this->sender->sendJson($endpointCF, $payloadCF, ['X-API-Key' => $apiKey], 1, 2, 'PUT');
                if (!$res['ok']) {
                    try {
                        $failed = $this->failedRequestFactory->create();
                        $failed->setData([
                            'endpoint' => $endpointCF,
                            'method' => 'PUT',
                            'headers' => json_encode(['X-API-Key' => '***']),
                            'payload' => json_encode($payloadCF),
                            'error' => (string)$res['body'],
                            'retry_count' => 0,
                            'status' => 0,
                            'next_attempt_at' => null,
                        ]);
                        $this->failedRequestResource->save($failed);
                    } catch (\Throwable $e) {
                        $this->logger->error('[Stellion_Pricemind] Failed to persist failed request for custom field: ' . $e->getMessage());
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Stellion_Pricemind] Failed to sync editable custom fields: ' . $e->getMessage());
        }
    }

    /**
     * Get product attribute value safely
     */
    private function getProductAttributeValue(Product $product, string $attributeCode): ?string
    {
        try {
            $value = $product->getData($attributeCode);
            if ($value === null || $value === '') {
                return null;
            }
            return (string)$value;
        } catch (\Throwable $e) {
            $this->logger->info('[Stellion_Pricemind] Could not get attribute value for: ' . $attributeCode . ' - ' . $e->getMessage());
            return null;
        }
    }
}
