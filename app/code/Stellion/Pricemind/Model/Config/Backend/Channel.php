<?php

namespace Stellion\Pricemind\Model\Config\Backend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\Value;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Stellion\Pricemind\Model\Api\Client as ApiClient;

class Channel extends Value
{
    /** @var WriterInterface */
    private $configWriter;
    /** @var ApiClient */
    private $apiClient;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $om = \Magento\Framework\App\ObjectManager::getInstance();
        $this->configWriter = $om->get(WriterInterface::class);
        $this->apiClient = $om->get(ApiClient::class);
        $this->logger = $om->get(LoggerInterface::class);
    }

    public function afterSave()
    {
        try {
            $channelId = (string)$this->getValue();
            if ($channelId !== '') {
                $websiteCode = $this->getScope() === ScopeInterface::SCOPE_WEBSITES ? (string)$this->getScopeCode() : null;
                // Compute scope early so we can persist detected fields reliably
                $scope = $websiteCode ? ScopeInterface::SCOPE_WEBSITES : ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
                $scopeId = $websiteCode ? (int)$this->getScopeId() : 0;

                $source = $this->apiClient->getActiveChannelSource($channelId, $websiteCode);
                $isMagento = false;
                $sourceType = '';
                $sourceTitle = '';
                $detectedFromMachine = '';
                $detectedToMachine = '';
                if (is_array($source)) {
                    $sourceType = (string)($source['type'] ?? '');
                    $sourceTitle = (string)($source['title'] ?? '');
                    $config = isset($source['config']) && is_array($source['config']) ? $source['config'] : [];
                    $mapping = isset($config['mapping']) && is_array($config['mapping']) ? $config['mapping'] : [];
                    // Heuristics to detect Magento
                    if (strtolower($sourceType) === 'magento' || $sourceTitle === 'Magento') {
                        $isMagento = true;
                    } elseif (isset($mapping['sku_attribute']) && $mapping['sku_attribute'] === 'sku') {
                        $isMagento = true;
                    }

                    // Try to auto-detect custom fields for special price from/to
                    $specialFromAttr = '';
                    $specialToAttr = '';
                    if (isset($mapping['special_price']) && is_array($mapping['special_price'])) {
                        $sp = $mapping['special_price'];
                        $specialFromAttr = (string)($sp['from_attribute'] ?? '');
                        $specialToAttr = (string)($sp['to_attribute'] ?? '');
                    }
                    // holders already declared above
                    // Support both mapping.custom_field and mapping.custom_fields
                    $cfMap = [];
                    if (isset($mapping['custom_field'])) {
                        $cfMap = $mapping['custom_field'];
                    }
                    if (empty($cfMap) && isset($mapping['custom_fields'])) {
                        $cfMap = $mapping['custom_fields'];
                    }
                    if (is_array($cfMap)) {
                        // Case 1: associative map attribute => machine_name
                        if ($specialFromAttr !== '' && isset($cfMap[$specialFromAttr]) && is_string($cfMap[$specialFromAttr])) {
                            $detectedFromMachine = (string)$cfMap[$specialFromAttr];
                        }
                        if ($specialToAttr !== '' && isset($cfMap[$specialToAttr]) && is_string($cfMap[$specialToAttr])) {
                            $detectedToMachine = (string)$cfMap[$specialToAttr];
                        }
                        // Case 1b: associative map machine_name => attribute (inverse)
                        if ($detectedFromMachine === '' && $specialFromAttr !== '') {
                            foreach ($cfMap as $k => $v) {
                                if (is_string($k) && is_string($v) && $v === $specialFromAttr) {
                                    $detectedFromMachine = (string)$k;
                                    break;
                                }
                            }
                        }
                        if ($detectedToMachine === '' && $specialToAttr !== '') {
                            foreach ($cfMap as $k => $v) {
                                if (is_string($k) && is_string($v) && $v === $specialToAttr) {
                                    $detectedToMachine = (string)$k;
                                    break;
                                }
                            }
                        }
                        // Case 2: list of objects with attribute/machine_name
                        if (($detectedFromMachine === '' || $detectedToMachine === '')) {
                            foreach ($cfMap as $item) {
                                if (!is_array($item)) {
                                    continue;
                                }
                                $attr = isset($item['attribute']) ? (string)$item['attribute'] : '';
                                $machine = isset($item['machine_name']) ? (string)$item['machine_name'] : '';
                                if ($attr === '' || $machine === '') {
                                    continue;
                                }
                                if ($detectedFromMachine === '' && $specialFromAttr !== '' && $attr === $specialFromAttr) {
                                    $detectedFromMachine = $machine;
                                }
                                if ($detectedToMachine === '' && $specialToAttr !== '' && $attr === $specialToAttr) {
                                    $detectedToMachine = $machine;
                                }
                                if ($detectedFromMachine !== '' && $detectedToMachine !== '') {
                                    break;
                                }
                            }
                        }
                    }
                    // If config explicitly contains machine names under mapping.special_price
                    if ($detectedFromMachine === '' && isset($mapping['special_price']['from_custom_field'])) {
                        $detectedFromMachine = (string)$mapping['special_price']['from_custom_field'];
                    }
                    if ($detectedToMachine === '' && isset($mapping['special_price']['to_custom_field'])) {
                        $detectedToMachine = (string)$mapping['special_price']['to_custom_field'];
                    }

                    // Fallback: use attribute names as machine names when nothing else provided
                    if ($detectedFromMachine === '' && $specialFromAttr !== '') {
                        $detectedFromMachine = $specialFromAttr;
                    }
                    if ($detectedToMachine === '' && $specialToAttr !== '') {
                        $detectedToMachine = $specialToAttr;
                    }
                }

                // Persist detection results at website scope
                $this->configWriter->save('stellion_pricemind/api/source_is_magento', $isMagento ? '1' : '0', $scope, $scopeId);
                if ($sourceType !== '') {
                    $this->configWriter->save('stellion_pricemind/api/source_type', $sourceType, $scope, $scopeId);
                }
                if ($sourceTitle !== '') {
                    $this->configWriter->save('stellion_pricemind/api/source_title', $sourceTitle, $scope, $scopeId);
                }
                // No longer persisting special_from/to machine names; observer uses static names
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Pricemind] Failed to fetch/store active channel source: ' . $e->getMessage());
        }

        return parent::afterSave();
    }
}
