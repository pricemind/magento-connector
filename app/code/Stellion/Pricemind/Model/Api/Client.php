<?php

namespace Stellion\Pricemind\Model\Api;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\RequestInterface;
use Psr\Log\LoggerInterface;

class Client
{
    const XML_PATH_BASE_URL = 'stellion_pricemind/api/base_url';
    const XML_PATH_API_KEY  = 'stellion_pricemind/api/access_key';
    const XML_PATH_CHANNEL_ID = 'stellion_pricemind/api/channel_id';

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var Curl */
    private $curl;

    /** @var JsonSerializer */
    private $json;

    /** @var RequestInterface */
    private $request;

    /** @var LoggerInterface */
    private $logger;

    /** @var EncryptorInterface */
    private $encryptor;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Curl $curl,
        JsonSerializer $json,
        RequestInterface $request,
        LoggerInterface $logger,
        EncryptorInterface $encryptor
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->curl = $curl;
        $this->json = $json;
        $this->request = $request;
        $this->logger = $logger;
        $this->encryptor = $encryptor;
    }

    public function getBaseUrl(?string $websiteCode = null): string
    {
        $websiteCode = $websiteCode !== null ? $websiteCode : $this->request->getParam('website');
        $baseUrl = (string)$this->scopeConfig->getValue(
            self::XML_PATH_BASE_URL,
            $websiteCode ? ScopeInterface::SCOPE_WEBSITE : ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            $websiteCode
        );
        return rtrim($baseUrl, '/');
    }

    public function getApiKey(?string $websiteCode = null): string
    {
        $websiteCode = $websiteCode !== null ? $websiteCode : $this->request->getParam('website');
        $encrypted = (string)$this->scopeConfig->getValue(
            self::XML_PATH_API_KEY,
            $websiteCode ? ScopeInterface::SCOPE_WEBSITE : ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            $websiteCode
        );
        if ($encrypted === '') {
            return '';
        }
        try {
            $apiKey = $this->encryptor->decrypt($encrypted);
            return trim((string)$apiKey);
        } catch (\Throwable $e) {
            $this->logger->error('[Pricemind] Failed to decrypt API key: ' . $e->getMessage());
            return '';
        }
    }

    public function listChannels(): array
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            return [];
        }

        $url = $this->getBaseUrl() . '/v1/channels';

        try {
            $this->curl->setHeaders([
                'Content-Type' => 'application/json',
                'X-API-Key' => $apiKey,
            ]);
            $this->curl->setTimeout(10);
            $this->curl->get($url);

            $status = (int)$this->curl->getStatus();
            $body = (string)$this->curl->getBody();

            if ($status < 200 || $status >= 300) {
                $this->logger->warning('[Pricemind] Non-2xx fetching channels', ['status' => $status, 'body' => $body]);
                return [];
            }

            $decoded = $this->json->unserialize($body);
            if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
                $this->logger->warning('[Pricemind] Unexpected channels response', ['body' => $body]);
                return [];
            }
            return $decoded['data'];
        } catch (\Throwable $e) {
            $this->logger->error('[Pricemind] Error fetching channels: ' . $e->getMessage());
            return [];
        }
    }

    public function getActiveChannelSource(string $channelId, ?string $websiteCode = null): ?array
    {
        $apiKey = $this->getApiKey($websiteCode);
        if ($apiKey === '') {
            return null;
        }

        $url = $this->getBaseUrl($websiteCode) . '/v1/channels/' . rawurlencode($channelId) . '/sources/active';

        try {
            $this->curl->setHeaders([
                'Content-Type' => 'application/json',
                'X-API-Key' => $apiKey,
            ]);
            $this->curl->setTimeout(10);
            $this->curl->get($url);

            $status = (int)$this->curl->getStatus();
            $body = (string)$this->curl->getBody();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('[Pricemind] Non-2xx fetching active channel source', ['status' => $status, 'body' => $body]);
                return null;
            }
            $decoded = $this->json->unserialize($body);
            if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
                $this->logger->warning('[Pricemind] Unexpected active source response', ['body' => $body]);
                return null;
            }
            return $decoded['data'];
        } catch (\Throwable $e) {
            $this->logger->error('[Pricemind] Error fetching active channel source: ' . $e->getMessage());
            return null;
        }
    }

    public function lookupProductDomainIdBySKU(string $channelId, string $sku, ?string $websiteCode = null): ?int
    {
        $apiKey = $this->getApiKey($websiteCode);
        if ($apiKey === '') {
            return null;
        }
        $url = $this->getBaseUrl($websiteCode) . '/v1/channels/' . rawurlencode($channelId) . '/product-domain?sku=' . rawurlencode($sku);
        try {
            $this->curl->setHeaders([
                'Content-Type' => 'application/json',
                'X-API-Key' => $apiKey,
            ]);
            $this->curl->setTimeout(10);
            $this->curl->get($url);

            $status = (int)$this->curl->getStatus();
            $body = (string)$this->curl->getBody();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('[Pricemind] Non-2xx product domain lookup', ['status' => $status, 'body' => $body]);
                return null;
            }
            $decoded = $this->json->unserialize($body);
            if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
                return null;
            }
            $id = $decoded['data']['product_domain_id'] ?? null;
            return $id !== null ? (int)$id : null;
        } catch (\Throwable $e) {
            $this->logger->error('[Pricemind] Error looking up product domain: ' . $e->getMessage());
            return null;
        }
    }
}
