<?php
/**
 * Unit tests for Channels Source Model
 */

namespace Stellion\Pricemind\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Stellion\Pricemind\Model\Config\Source\Channels;
use Stellion\Pricemind\Model\Api\Client as ApiClient;

class ChannelsTest extends TestCase
{
    /** @var Channels */
    private $channelsSource;

    /** @var MockObject|ApiClient */
    private $apiClientMock;

    protected function setUp(): void
    {
        $this->apiClientMock = $this->createMock(ApiClient::class);
        $this->channelsSource = new Channels($this->apiClientMock);
    }

    public function testToOptionArrayWithChannels(): void
    {
        $channels = [
            ['id' => 1, 'name' => 'Test Channel 1'],
            ['id' => 2, 'name' => 'Test Channel 2'],
        ];

        $this->apiClientMock->expects($this->once())
            ->method('listChannels')
            ->willReturn($channels);

        $result = $this->channelsSource->toOptionArray();

        $expected = [
            ['value' => '', 'label' => '-- Please Select --'],
            ['value' => '1', 'label' => 'Test Channel 1'],
            ['value' => '2', 'label' => 'Test Channel 2'],
        ];

        $this->assertEquals($expected, $result);
    }

    public function testToOptionArrayWithNoChannels(): void
    {
        $this->apiClientMock->expects($this->once())
            ->method('listChannels')
            ->willReturn([]);

        $result = $this->channelsSource->toOptionArray();

        $expected = [
            ['value' => '', 'label' => '-- Please Select --'],
        ];

        $this->assertEquals($expected, $result);
    }

    public function testToOptionArrayWithApiError(): void
    {
        $this->apiClientMock->expects($this->once())
            ->method('listChannels')
            ->willReturn([]);

        $result = $this->channelsSource->toOptionArray();

        $expected = [
            ['value' => '', 'label' => '-- Please Select --'],
        ];

        $this->assertEquals($expected, $result);
    }

    public function testToOptionArrayWithMalformedChannels(): void
    {
        // Test with channels missing required fields
        $channels = [
            ['id' => 1, 'name' => 'Valid Channel'],
            ['id' => 2], // Missing name
            ['name' => 'Missing ID'], // Missing id
            ['id' => 3, 'name' => 'Another Valid Channel'],
        ];

        $this->apiClientMock->expects($this->once())
            ->method('listChannels')
            ->willReturn($channels);

        $result = $this->channelsSource->toOptionArray();

        $expected = [
            ['value' => '', 'label' => '-- Please Select --'],
            ['value' => '1', 'label' => 'Valid Channel'],
            ['value' => '2', 'label' => ''], // Handle missing name gracefully
            ['value' => '', 'label' => 'Missing ID'], // Handle missing ID
            ['value' => '3', 'label' => 'Another Valid Channel'],
        ];

        $this->assertEquals($expected, $result);
    }

    public function testToOptionArrayCaching(): void
    {
        $channels = [
            ['id' => 1, 'name' => 'Test Channel 1'],
        ];

        // Should only call API once due to caching
        $this->apiClientMock->expects($this->once())
            ->method('listChannels')
            ->willReturn($channels);

        $result1 = $this->channelsSource->toOptionArray();
        $result2 = $this->channelsSource->toOptionArray();

        $this->assertEquals($result1, $result2);
    }
}
