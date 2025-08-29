<?php

namespace Stellion\Pricemind\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Stellion\Pricemind\Model\Api\Client as ApiClient;

class Channels implements OptionSourceInterface
{
    /** @var ApiClient */
    private $client;

    public function __construct(ApiClient $client)
    {
        $this->client = $client;
    }

    public function toOptionArray()
    {
        $options = [
            [
                'label' => __('-- Please select a channel --'),
                'value' => ''
            ]
        ];

        $channels = $this->client->listChannels();
        foreach ($channels as $channel) {
            $label = isset($channel['name']) ? (string)$channel['name'] : (string)($channel['channel_id'] ?? '');
            $value = (string)($channel['channel_id'] ?? '');
            if ($value === '') {
                continue;
            }
            $options[] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        return $options;
    }
}


