<?php
/**
 * Simple validation test to ensure our test setup works
 */

namespace Stellion\Pricemind\Test\Unit;

use PHPUnit\Framework\TestCase;

class SimpleValidationTest extends TestCase
{
    public function testBasicPhpFunctionality(): void
    {
        $this->assertTrue(true);
        $this->assertEquals(2, 1 + 1);
        $this->assertIsString('hello');
    }

    public function testMagentoMocksAreLoaded(): void
    {
        $observer = new \Magento\Framework\Event\Observer();
        $this->assertInstanceOf(\Magento\Framework\Event\Observer::class, $observer);
        
        $product = new \Magento\Catalog\Model\Product();
        $this->assertInstanceOf(\Magento\Catalog\Model\Product::class, $product);
        $this->assertEquals('TEST-SKU', $product->getSku());
    }

    public function testOurClassesCanBeLoaded(): void
    {
        // Test that our classes can be instantiated (with mocked dependencies)
        $this->assertTrue(class_exists('Stellion\Pricemind\Model\Sender'));
        $this->assertTrue(class_exists('Stellion\Pricemind\Observer\ProductPriceChangeObserver'));
        $this->assertTrue(class_exists('Stellion\Pricemind\Model\Api\Client'));
    }

    public function testJsonSerialization(): void
    {
        $data = ['product_sku' => 'TEST-SKU', 'price' => '100.00'];
        $json = json_encode($data);
        $decoded = json_decode($json, true);
        
        $this->assertEquals($data, $decoded);
    }
}
