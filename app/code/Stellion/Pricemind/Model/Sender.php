<?php

namespace Stellion\Pricemind\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

class Sender
{
    /** @var Curl */
    private $curl;
    /** @var LoggerInterface */
    private $logger;
    /** @var JsonSerializer */
    private $json;

    public function __construct(Curl $curl, LoggerInterface $logger, JsonSerializer $json)
    {
        $this->curl = $curl;
        $this->logger = $logger;
        $this->json = $json;
    }

    public function sendJson(string $endpointUrl, array $payload, array $headers = [], int $connectTimeoutSeconds = 1, int $timeoutSeconds = 2, string $method = 'POST'): array
    {
        if ($endpointUrl === '') {
            return ['ok' => true, 'status' => null, 'body' => null];
        }

        try {
            $this->curl->setHeaders(array_merge([
                'Content-Type' => 'application/json'
            ], $headers));
            // Short timeouts to avoid blocking admin/frontend
            if (method_exists($this->curl, 'setConnectTimeout')) {
                $this->curl->setConnectTimeout($connectTimeoutSeconds);
            }
            if (method_exists($this->curl, 'setTimeout')) {
                $this->curl->setTimeout($timeoutSeconds);
            }
            $body = $this->json->serialize($payload);
            $methodUpper = strtoupper($method);
            if ($methodUpper === 'POST') {
                $this->curl->post($endpointUrl, $body);
            } else {
                // Force custom verb (e.g., PUT/PATCH/DELETE) using CURL option, then send body
                if (method_exists($this->curl, 'setOption')) {
                    $this->curl->setOption(CURLOPT_CUSTOMREQUEST, $methodUpper);
                }
                // Magento Curl client doesn't always expose put/patch helpers; use POST transport with custom verb
                $this->curl->post($endpointUrl, $body);
            }
            $status = (int)$this->curl->getStatus();
            $body = (string)$this->curl->getBody();
            $ok = ($status >= 200 && $status < 300);
            if (!$ok) {
                $this->logger->warning('[Stellion_Pricemind] Non-2xx response', [
                    'status' => $status,
                    'body' => $body
                ]);
            }
            return ['ok' => $ok, 'status' => $status, 'body' => $body];
        } catch (\Throwable $e) {
            // Swallow errors to avoid disrupting the original request
            $this->logger->warning('[Stellion_Pricemind] Send failed', [
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'status' => null, 'body' => $e->getMessage()];
        }
    }
}
