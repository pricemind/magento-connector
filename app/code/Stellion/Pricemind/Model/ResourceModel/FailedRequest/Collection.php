<?php

namespace Stellion\Pricemind\Model\ResourceModel\FailedRequest;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct()
    {
        $this->_init(\Stellion\Pricemind\Model\FailedRequest::class, \Stellion\Pricemind\Model\ResourceModel\FailedRequest::class);
    }
}
