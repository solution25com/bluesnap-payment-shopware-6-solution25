<?php

declare(strict_types=1);

namespace BlueSnap\Service;

use BlueSnap\Exceptions\AppleWalletCaptureException;
use BlueSnap\Exceptions\BlueSnapTokenRequestException;
use BlueSnap\Exceptions\CreditCardCaptureRequestException;
use BlueSnap\Exceptions\HostedCheckoutException;
use BlueSnap\Exceptions\RefundException;
use BlueSnap\Exceptions\UpdateVaultedShopperException;
use BlueSnap\Exceptions\VaultedShopperException;
use BlueSnap\Library\Constants\EnvironmentUrl;
use BlueSnap\Library\Endpoints;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class BlueSnapApiClient extends Endpoints
{
    private BlueSnapConfig $blueSnapConfig;
    private string $token;
    private Client $client;
    private LoggerInterface $logger;

    public function __construct(BlueSnapConfig $blueSnapConfig, LoggerInterface $logger)
    {
        $this->blueSnapConfig = $blueSnapConfig;
        $this->logger         = $logger;
    }

    private function setupClient(string $salesChannelId = ''): void
    {
        $mode   = $this->blueSnapConfig->getConfig('mode', $salesChannelId);
        $isLive = $mode === 'live';

        $baseUrl     = $isLive ? EnvironmentUrl::LIVE : EnvironmentUrl::SANDBOX;
        $apiKey      = $this->blueSnapConfig->getConfig($isLive ? 'apiKeyLive' : 'apiKeySandbox', $salesChannelId);
        $apiPassword = $this->blueSnapConfig->getConfig($isLive ? 'apiPasswordLive' : 'apiPasswordSandbox', $salesChannelId);

        if (empty($apiKey)) {
            $apiKey = '';
        }
        if (empty($apiPassword)) {
            $apiPassword = '';
        }

        $this->client = new Client(['base_uri' => $baseUrl->value]);
        $this->token  = base64_encode(trim($apiKey) . ':' . trim($apiPassword));
    }

    private function getDefaultOptions($body): array
    {
        return [
          'headers' => [
            'Authorization' => 'Basic ' . $this->token,
            'Content-Type'  => 'application/json'
          ],
          'body' => json_encode($body)
        ];
    }

    private function request(array $endpoint, $options): ResponseInterface|array
    {
        try {
            ['method' => $method, 'url' => $url] = $endpoint;
            return $this->client->request($method, $url, $options);
        } catch (GuzzleException $e) {
            if ($e->hasResponse()) {
                $responseBody = $e->getResponse()->getBody()->getContents();
                $decodedBody  = json_decode($responseBody, true);
                return [
                  'error'   => true,
                  'code'    => $e->getCode(),
                  'message' => $decodedBody['message'] ?? $decodedBody,
                ];
            } else {
                return [
                  'error'   => true,
                  'code'    => $e->getCode(),
                  'message' => $e->getMessage(),
                ];
            }
        }
    }

    public function makeTokenRequest(?array $query = [], string $salesChannelId = ''): string|array
    {
        $this->setupClient($salesChannelId);

        $options = [
          'headers' => [
            'Authorization' => 'Basic ' . $this->token
          ],
        ];
        try {
            $response = $this->request(self::getUrlDynamicParam(self::PAYMENT_FIELD_TOKENS, [], $query), $options);
            if (is_array($response) && isset($response['error'])) {
                throw new BlueSnapTokenRequestException($this->logger);
            }
            $splitLocation = explode('/', $response->getHeader('location')[0]);
            return $splitLocation[count($splitLocation) - 1];
        } catch (BlueSnapTokenRequestException $e) {
            return [
              'error'   => true,
              'code'    => $e->getCode(),
              'message' => $e->getMessage()
            ];
        }
    }

    public function capture(array $body, string $salesChannelId = ''): string | array
    {
        $this->setupClient($salesChannelId);
        $options = $this->getDefaultOptions($body);
        try {
            $response = $this->request(self::getEndpoint(self::TRANSACTION), $options);
            if (is_array($response) && isset($response['error'])) {
                throw new CreditCardCaptureRequestException($this->logger, json_encode($response['message']), $response['code']);
            }
            return $response->getBody()->getContents();
        } catch (CreditCardCaptureRequestException $e) {
            return  [
              "error"   => true,
              'code'    => $e->getCode(),
              "message" => $e->getMessage()
            ];
        }
    }
    public function appleWalletRequest(array $body, string $salesChannelId = ''): string|array
    {
        $this->setupClient($salesChannelId);
        $options = $this->getDefaultOptions($body);
        try {
            $response = $this->request(self::getEndpoint(self::APPLE_WALLET), $options);
            if (is_array($response) && isset($response['error'])) {
                throw new AppleWalletCaptureException($this->logger, json_encode($response['message']), $response['code']);
            }
            return $response->getBody()->getContents();
        } catch (AppleWalletCaptureException $e) {
            return [
              'error'   => true,
              'code'    => $e->getCode(),
              'message' => $e->getMessage()
            ];
        }
    }
    public function getVaultedShopper(string $id, $salesChannelId = ''): string|array
    {
        $this->setupClient($salesChannelId);
        $url = Endpoints::getUrl(Endpoints::VAULTED_SHOPPERS, $id);

        $options = [
          'headers' => [
            'Authorization' => 'Basic ' . $this->token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json'
          ],
        ];
        try {
            $response = $this->request($url, $options);
            if (is_array($response) && isset($response['error'])) {
                throw new VaultedShopperException($this->logger, json_encode($response['message']), $response['code']);
            }
            return $response->getBody()->getContents();
        } catch (VaultedShopperException $e) {
            return [
              'error'   => true,
              'code'    => $e->getCode(),
              'message' => $e->getMessage()
            ];
        }
    }
    public function hostedCheckout(array $body, $salesChannelId = ''): string|array
    {
        $this->setupClient($salesChannelId);
        $options = $this->getDefaultOptions($body);
        try {
            $response = $this->request(self::getEndpoint(self::HOSTED_CHECKOUT), $options);
            if (is_array($response) && isset($response['error'])) {
                throw new HostedCheckoutException($this->logger, json_encode($response['message']), $response['code']);
            }
            return $response->getBody()->getContents();
        } catch (HostedCheckoutException $e) {
            return [
              'error'   => true,
              'code'    => $e->getCode(),
              'message' => $e->getMessage()
            ];
        }
    }
    public function updateVaultedShopper(string $id, $body, string $salesChannelId = ''): string|array
    {
        $this->setupClient($salesChannelId);
        $options = $this->getDefaultOptions($body);
        try {
            $response = $this->request(self::getUrlDynamicParam(self::UPDATE_SHOPPER, [$id]), $options);
            if (is_array($response) && isset($response['error'])) {
                throw new UpdateVaultedShopperException($this->logger, json_encode($response['message']), $response['code']);
            }
            return $response->getBody()->getContents();
        } catch (UpdateVaultedShopperException $e) {
            return [
              'error'   => true,
              'code'    => $e->getCode(),
              'message' => $e->getMessage()
            ];
        }
    }
    public function refund(string $transactionId, $body, string $salesChannelId = ''): string|array
    {
        $this->setupClient($salesChannelId);
        $options = $this->getDefaultOptions($body);
        try {
            $response = $this->request(self::getUrlDynamicParam(self::REFUNDS, [$transactionId]), $options);
            if (is_array($response) && isset($response['error'])) {
                throw new RefundException($this->logger, json_encode($response['message']), $response['code']);
            }
            return $response->getBody()->getContents();
        } catch (RefundException $e) {
            return [
              'error'   => true,
              'code'    => $e->getCode(),
              'message' => $e->getMessage()
            ];
        }
    }
}
