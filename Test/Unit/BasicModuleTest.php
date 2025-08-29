<?php
/**
 * Basic module functionality test
 */

namespace Stellion\Pricemind\Test\Unit;

use PHPUnit\Framework\TestCase;
use Stellion\Pricemind\Model\Sender;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;

class BasicModuleTest extends TestCase
{
    public function testSenderCanBeInstantiated(): void
    {
        $curlMock = $this->createMock(Curl::class);
        $loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);
        $jsonMock = $this->createMock(Json::class);
        
        $sender = new Sender($curlMock, $loggerMock, $jsonMock);
        
        $this->assertInstanceOf(Sender::class, $sender);
    }
    
    public function testSenderHandlesEmptyUrl(): void
    {
        $curlMock = $this->createMock(Curl::class);
        $loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);
        $jsonMock = $this->createMock(Json::class);
        
        $sender = new Sender($curlMock, $loggerMock, $jsonMock);
        $result = $sender->sendJson('', ['test' => 'data']);
        
        $this->assertEquals(['ok' => true, 'status' => null, 'body' => null], $result);
    }
    
    public function testObserverClassExists(): void
    {
        $this->assertTrue(class_exists(\Stellion\Pricemind\Observer\ProductPriceChangeObserver::class));
    }
    
    public function testApiClientClassExists(): void
    {
        $this->assertTrue(class_exists(\Stellion\Pricemind\Model\Api\Client::class));
    }
}
