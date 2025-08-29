<?php
/**
 * Unit tests for Channel Backend Model
 */

namespace Stellion\Pricemind\Test\Unit\Model\Config\Backend;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Stellion\Pricemind\Model\Config\Backend\Channel;
use Stellion\Pricemind\Model\Api\Client as ApiClient;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;

class ChannelTest extends TestCase
{
    /** @var Channel */
    private $channelBackend;

    /** @var MockObject|ApiClient */
    private $apiClientMock;

    /** @var MockObject|Context */
    private $contextMock;

    /** @var MockObject|Registry */
    private $registryMock;

    /** @var MockObject|ScopeConfigInterface */
    private $scopeConfigMock;

    /** @var MockObject|TypeListInterface */
    private $cacheTypeListMock;

    protected function setUp(): void
    {
        $this->apiClientMock = $this->createMock(ApiClient::class);
        $this->contextMock = $this->createMock(Context::class);
        $this->registryMock = $this->createMock(Registry::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->cacheTypeListMock = $this->createMock(TypeListInterface::class);

        $this->channelBackend = new Channel(
            $this->contextMock,
            $this->registryMock,
            $this->scopeConfigMock,
            $this->cacheTypeListMock,
            $this->apiClientMock,
            null, // resource
            null  // resourceCollection
        );
    }

    public function testAfterSaveWithValidChannel(): void
    {
        $channelId = '123';
        $activeSource = [
            'type' => 'magento',
            'title' => 'Test Magento Store',
            'is_magento' => true
        ];

        $this->channelBackend->setValue($channelId);

        $this->apiClientMock->expects($this->once())
            ->method('getActiveChannelSource')
            ->with($channelId)
            ->willReturn($activeSource);

        $this->scopeConfigMock->expects($this->exactly(3))
            ->method('setValue')
            ->withConsecutive(
                ['stellion_pricemind/api/source_is_magento', 1],
                ['stellion_pricemind/api/source_type', 'magento'],
                ['stellion_pricemind/api/source_title', 'Test Magento Store']
            );

        $result = $this->channelBackend->afterSave();

        $this->assertInstanceOf(Channel::class, $result);
    }

    public function testAfterSaveWithNonMagentoSource(): void
    {
        $channelId = '123';
        $activeSource = [
            'type' => 'shopify',
            'title' => 'Test Shopify Store',
            'is_magento' => false
        ];

        $this->channelBackend->setValue($channelId);

        $this->apiClientMock->expects($this->once())
            ->method('getActiveChannelSource')
            ->with($channelId)
            ->willReturn($activeSource);

        $this->scopeConfigMock->expects($this->exactly(3))
            ->method('setValue')
            ->withConsecutive(
                ['stellion_pricemind/api/source_is_magento', 0],
                ['stellion_pricemind/api/source_type', 'shopify'],
                ['stellion_pricemind/api/source_title', 'Test Shopify Store']
            );

        $result = $this->channelBackend->afterSave();

        $this->assertInstanceOf(Channel::class, $result);
    }

    public function testAfterSaveWithEmptyChannel(): void
    {
        $this->channelBackend->setValue('');

        $this->apiClientMock->expects($this->never())
            ->method('getActiveChannelSource');

        $this->scopeConfigMock->expects($this->exactly(3))
            ->method('setValue')
            ->withConsecutive(
                ['stellion_pricemind/api/source_is_magento', 0],
                ['stellion_pricemind/api/source_type', ''],
                ['stellion_pricemind/api/source_title', '']
            );

        $result = $this->channelBackend->afterSave();

        $this->assertInstanceOf(Channel::class, $result);
    }

    public function testAfterSaveWithApiError(): void
    {
        $channelId = '123';

        $this->channelBackend->setValue($channelId);

        $this->apiClientMock->expects($this->once())
            ->method('getActiveChannelSource')
            ->with($channelId)
            ->willReturn(null); // API error

        $this->scopeConfigMock->expects($this->exactly(3))
            ->method('setValue')
            ->withConsecutive(
                ['stellion_pricemind/api/source_is_magento', 0],
                ['stellion_pricemind/api/source_type', ''],
                ['stellion_pricemind/api/source_title', '']
            );

        $result = $this->channelBackend->afterSave();

        $this->assertInstanceOf(Channel::class, $result);
    }

    public function testAfterSaveWithMissingSourceFields(): void
    {
        $channelId = '123';
        $activeSource = [
            'type' => 'custom'
            // Missing title and is_magento
        ];

        $this->channelBackend->setValue($channelId);

        $this->apiClientMock->expects($this->once())
            ->method('getActiveChannelSource')
            ->with($channelId)
            ->willReturn($activeSource);

        $this->scopeConfigMock->expects($this->exactly(3))
            ->method('setValue')
            ->withConsecutive(
                ['stellion_pricemind/api/source_is_magento', 0], // Default to false
                ['stellion_pricemind/api/source_type', 'custom'],
                ['stellion_pricemind/api/source_title', ''] // Default to empty
            );

        $result = $this->channelBackend->afterSave();

        $this->assertInstanceOf(Channel::class, $result);
    }

    public function testAfterSaveWithException(): void
    {
        $channelId = '123';

        $this->channelBackend->setValue($channelId);

        $this->apiClientMock->expects($this->once())
            ->method('getActiveChannelSource')
            ->with($channelId)
            ->willThrowException(new \Exception('API connection failed'));

        // Should still set default values even if exception occurs
        $this->scopeConfigMock->expects($this->exactly(3))
            ->method('setValue')
            ->withConsecutive(
                ['stellion_pricemind/api/source_is_magento', 0],
                ['stellion_pricemind/api/source_type', ''],
                ['stellion_pricemind/api/source_title', '']
            );

        $result = $this->channelBackend->afterSave();

        $this->assertInstanceOf(Channel::class, $result);
    }
}
