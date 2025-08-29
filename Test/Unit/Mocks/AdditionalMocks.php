<?php
/**
 * Additional Mock classes for complex Magento structures
 */

// Mock Config Block classes
namespace Magento\Config\Block\System\Config\Form {
    class Field {
        public function __construct() {}
        protected function _getElementHtml($element) {
            return '<input type="text" />';
        }
    }
}

namespace Magento\Backend\Block\Template {
    class Context {
        public function __construct() {}
    }
}

namespace Magento\Framework\Data\Form\Element {
    class AbstractElement {
        public function __construct() {}
    }
}

namespace Magento\Framework\App\Config {
    class Value {
        public function __construct() {}
        public function afterSave() {
            return $this;
        }
    }
}

namespace Magento\Framework\Model {
    class AbstractModel {
        public function __construct() {}
    }
}

namespace Magento\Framework\Model\ResourceModel\Db {
    class AbstractDb {
        public function __construct() {}
        protected function _init($mainTable, $idFieldName) {}
    }
}

namespace Magento\Framework\Model\ResourceModel\Db\Collection {
    class AbstractCollection {
        public function __construct() {}
    }
}

namespace Stellion\Pricemind\Model {
    class FailedRequestFactory {
        public function create() {
            return new class {
                public function setData($data) { return $this; }
            };
        }
    }
}

// Note: Removed mock for Stellion\Pricemind\Model\ResourceModel\FailedRequest
// to avoid conflict with the real class