<?php

namespace Stellion\Pricemind\Model;

use Magento\Framework\Model\AbstractModel;

class FailedRequest extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Stellion\Pricemind\Model\ResourceModel\FailedRequest::class);
    }
}


