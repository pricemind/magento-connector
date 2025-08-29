<?php
namespace Stellion\Pricemind\Model;

use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class Sender
{
    /** @var Curl */
    private $curl;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(Curl $curl, LoggerInterface $logger)
    {
        $this->curl = $curl;
        $this->logger = $logger;
    }

    public function sendJson(string $endpointUrl, array $payload, int $connectTimeoutSeconds = 1, int $timeoutSeconds = 2): bool
    {
        if ($endpointUrl === '') {
            return true; // nothing to do; keep non-blocking
        }

        try {
            $this->curl->setHeaders([
                'Content-Type' => 'application/json'
            ]);
            // Short timeouts to avoid blocking admin/frontend
            if (method_exists($this->curl, 'setConnectTimeout')) {
                $this->curl->setConnectTimeout($connectTimeoutSeconds);
            }
            if (method_exists($this->curl, 'setTimeout')) {
                $this->curl->setTimeout($timeoutSeconds);
            }
            $this->curl->post($endpointUrl, json_encode($payload));
            $status = (int)$this->curl->getStatus();
            if ($status >= 200 && $status < 300) {
                return true;
            }
            $this->logger->warning('[Stellion_Pricemind] Non-2xx response', [
                'status' => $status,
            ]);
            return false;
        } catch (\Throwable $e) {
            // Swallow errors to avoid disrupting the original request
            $this->logger->warning('[Stellion_Pricemind] Send failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

