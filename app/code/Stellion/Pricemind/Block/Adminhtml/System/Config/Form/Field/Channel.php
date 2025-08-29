<?php

namespace Stellion\Pricemind\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\RequestInterface;

class Channel extends Field
{
    const XML_PATH_API_KEY = 'stellion_pricemind/api/access_key';

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var RequestInterface */
    private $request;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        RequestInterface $request,
        \Magento\Backend\Block\Template\Context $context,
        array $data = []
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->request = $request;
        parent::__construct($context, $data);
    }

    protected function _getElementHtml($element)
    {
        $websiteCode = $this->request->getParam('website');
        $scope = $websiteCode ? ScopeInterface::SCOPE_WEBSITE : ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $apiKey = (string)$this->scopeConfig->getValue(self::XML_PATH_API_KEY, $scope, $websiteCode);

        if ($apiKey === '') {
            $element->setDisabled(true);
            $element->setComment(__('Add API Key and Save to enable channel selection.'));
        }

        return parent::_getElementHtml($element);
    }
}
