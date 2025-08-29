<?php
/**
 * Mock Magento Framework classes for standalone testing
 */

// Mock Magento Event classes
namespace Magento\Framework\Event {
    interface ObserverInterface {
        public function execute(Observer $observer);
    }
    
    class Observer {
        private $event;
        public function __construct($event = null) { $this->event = $event ?: new Event(); }
        public function getEvent() { return $this->event; }
    }
    
    class Event {
        private $data = [];
        public function getProduct() { return $this->data['product'] ?? new \Magento\Catalog\Model\Product(); }
        public function getData($key = null) { return $key ? ($this->data[$key] ?? null) : $this->data; }
        public function setData($key, $value) { $this->data[$key] = $value; }
    }
}

// Mock Magento Catalog classes
namespace Magento\Catalog\Model {
    class Product {
        private $data = [];
        private $origData = [];
        
        public function getSku() { return $this->data['sku'] ?? 'TEST-SKU'; }
        public function getStoreId() { return $this->data['store_id'] ?? 1; }
        public function getData($key = null) { return $key ? ($this->data[$key] ?? null) : $this->data; }
        public function getOrigData($key = null) { return $key ? ($this->origData[$key] ?? null) : $this->origData; }
        public function setData($key, $value) { $this->data[$key] = $value; return $this; }
        public function setOrigData($key, $value) { $this->origData[$key] = $value; return $this; }
    }
}

// Mock Magento Store classes
namespace Magento\Store\Model {
    class Store {
        private $website;
        public function __construct() { $this->website = new Website(); }
        public function getWebsite() { return $this->website; }
        public function getBaseCurrencyCode() { return 'USD'; }
    }
    
    class Website {
        public function getCode() { return 'default'; }
    }
    
    interface StoreManagerInterface {
        public function getStore($storeId = null);
    }
    
    interface ScopeInterface {
        const SCOPE_WEBSITE = 'website';
    }
}

// Mock Magento Framework classes
namespace Magento\Framework\App\Config {
    interface ScopeConfigInterface {
        const SCOPE_TYPE_DEFAULT = 'default';
        public function getValue($path, $scopeType = null, $scopeCode = null);
    }
}

namespace Magento\Framework\HTTP\Client {
    class Curl {
        private $status = 200;
        private $body = '{"success": true}';
        
        public function setHeaders(array $headers) {}
        public function setTimeout($timeout) {}
        public function setConnectTimeout($timeout) {}
        public function setOption($option, $value) {}
        public function get($url) {}
        public function post($url, $data) {}
        public function getStatus() { return $this->status; }
        public function getBody() { return $this->body; }
        public function setMockResponse($status, $body) { $this->status = $status; $this->body = $body; }
    }
}

namespace Magento\Framework\Serialize\Serializer {
    class Json {
        public function serialize($data) { return json_encode($data); }
        public function unserialize($data) { return json_decode($data, true); }
    }
}

namespace Magento\Framework\Encryption {
    interface EncryptorInterface {
        public function decrypt($data);
    }
}

namespace Magento\Framework\App {
    interface RequestInterface {
        public function getParam($name, $defaultValue = null);
    }
}

namespace Magento\Framework\Model {
    class Context {}
    class Registry {}
}

namespace Magento\Framework\Data {
    interface OptionSourceInterface {
        public function toOptionArray();
    }
}

namespace Magento\Framework\App\Cache {
    interface TypeListInterface {}
}

// Mock PSR Log
namespace Psr\Log {
    interface LoggerInterface {
        public function error($message, array $context = []);
        public function warning($message, array $context = []);
        public function info($message, array $context = []);
    }
}
