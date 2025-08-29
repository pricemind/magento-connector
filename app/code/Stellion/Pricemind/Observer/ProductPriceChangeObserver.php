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
        if (!$priceChanged && !$specialChanged && !$fromChanged && !$toChanged) {
            return; // No relevant change
        }

        $store = $this->storeManager->getStore((int)$product->getStoreId());
        $websiteCode = (string)$store->getWebsite()->getCode();

        $baseUrl = $this->apiClient->getBaseUrl($websiteCode);
        $apiKey = $this->apiClient->getApiKey($websiteCode);
        $channelId = (string)$this->scopeConfig->getValue('stellion_pricemind/api/channel_id', ScopeInterface::SCOPE_WEBSITE, $websiteCode);

        if ($apiKey === '' || $channelId === '') {
            return; // not configured
        }

        $endpoint = rtrim($baseUrl, '/') . '/v1/channels/' . rawurlencode($channelId) . '/prices';

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
        ], 1, 2);

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
                            $this->logger->error('[Stellion_Pricemind] Failed to persist failed request: ' . $e->getMessage());
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[Stellion_Pricemind] Failed to update special date custom fields: ' . $e->getMessage());
            }
        }
    }
}
