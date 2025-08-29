<?php
namespace Stellion\Pricemind\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Catalog\Model\Product;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Stellion\Pricemind\Model\Sender;

class ProductPriceChangeObserver implements ObserverInterface
{
    /** @var Sender */
    private $sender;
    /** @var LoggerInterface */
    private $logger;
    /** @var ScopeConfigInterface */
    private $scopeConfig;

    public function __construct(
        Sender $sender,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->sender = $sender;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
    }

    public function execute(Observer $observer)
    {
        /** @var Product $product */
        $product = $observer->getEvent()->getProduct();

        $origPrice = $product->getOrigData('price');
        $newPrice = $product->getData('price');

        if ($origPrice === null || (string)$origPrice === (string)$newPrice) {
            return; // No price change
        }

        $endpoint = (string)$this->scopeConfig->getValue('stellion_pricemind/general/endpoint_url');

        $payload = [
            'product_id' => (int)$product->getId(),
            'store_id' => (int)$product->getStoreId(),
            'sku' => (string)$product->getSku(),
            'old_price' => (string)$origPrice,
            'new_price' => (string)$newPrice,
            'changed_at' => gmdate('c'),
        ];

        $sent = $this->sender->sendJson($endpoint, $payload);
        if (!$sent) {
            $this->logger->warning('[Stellion_Pricemind] Price change not sent');
        }
    }
}
