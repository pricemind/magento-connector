<?php
/**
 * Storage-related Mock classes
 */

namespace Magento\Framework\App\Config\Storage {
    interface WriterInterface {
        public function save($path, $value, $scope = null, $scopeId = null);
    }
}
