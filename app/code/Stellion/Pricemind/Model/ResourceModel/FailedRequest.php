<?php

namespace Stellion\Pricemind\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class FailedRequest extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('stellion_pricemind_failed_request', 'entity_id');
    }
}
