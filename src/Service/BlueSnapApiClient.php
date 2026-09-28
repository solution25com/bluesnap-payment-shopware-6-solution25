<?php

declare(strict_types=1);

namespace BlueSnap\Service;

use BlueSnap\Exceptions\AppleWalletCaptureException;
use BlueSnap\Exceptions\BaseException;
use BlueSnap\Exceptions\BlueSnapTokenRequestException;
use BlueSnap\Exceptions\CreditCardCaptureRequestException;
use BlueSnap\Exceptions\HostedCheckoutException;
use BlueSnap\Exceptions\RefundException;
use BlueSnap\Exceptions\SavedCardException;
use BlueSnap\Exceptions\UpdateVaultedShopperException;
use BlueSnap\Exceptions\VaultedShopperException;
use BlueSnap\Library\Constants\EnvironmentUrl;
use BlueSnap\Library\Endpoints;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class BlueSnapApiClient extends Endpoints
{
    private BlueSnapConfig $blueSnapConfig;
    private string $token;
    private Client $client;
    /** @var array<string, array{client: Client, token: string}> */
    private array $clientCache = [];
    private LoggerInterface $logger;

    public function __construct(BlueSnapConfig $blueSnapConfig, LoggerInterface $logger)
    {
        $this->blueSnapConfig = $blueSnapConfig;
        $this->logger = $logger;
    }

    private function setupClient(string $salesChannelId = ''): void
    {
        if (isset($this->clientCache[$salesChannelId])) {
            $this->client = $this->clientCache[$salesChannelId]['client'];
            $this->token = $this->clientCache[$salesChannelId]['token'];

            return;
        }

        $mode = $this->blueSnapConfig->getConfig('mode', $salesChannelId);
        $isLive = $mode === 'live';

        $baseUrl = $isLive ? EnvironmentUrl::LIVE : EnvironmentUrl::SANDBOX;
        $apiKey = $this->blueSnapConfig->getConfig($isLive ? 'apiKeyLive' : 'apiKeySandbox', $salesChannelId);
        $apiPassword = $this->blueSnapConfig->getConfig($isLive ? 'apiPasswordLive' : 'apiPasswordSandbox', $salesChannelId);

        if (empty($apiKey)) {
            $apiKey = '';
        }
        if (empty($apiPassword)) {
            $apiPassword = '';
        }

        $this->client = new Client([
            'base_uri' => $baseUrl->value,
            'connect_timeout' => 5,
            'timeout' => 30,
        ]);
        $this->token = base64_encode(trim($apiKey) . ':' . trim($apiPassword));

        $this->clientCache[$salesChannelId] = ['client' => $this->client, 'token' => $this->token];
    }

    private function getDefaultOptions($body, string $idempotencyKey = ''): array
    {
        $headers = [
            'Authorization' => 'Basic ' . $this->token,
            'Content-Type' => 'application/json',
        ];

        return [
            'headers' => $idempotencyKey === ''
                ? $headers
                : $headers + ['idempotency-key' => $idempotencyKey],
            'body' => json_encode($body)
        ];
    }

    private function buildIdempotencyKey(string $operation, string $reference): string
    {
        if ($reference === '') {
            return '';
        }

        return substr($operation, 0, 3) . '-' . substr(hash('sha256', $operation . '|' . $reference), 0, 59);
    }

    private function request(array $endpoint, $options): ResponseInterface|array
    {
        try {
            ['method' => $method, 'url' => $url] = $endpoint;
            return $this->client->request($method, $url, $options);
        } catch (ConnectException $e) {
            return [
                'error' => true,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ];
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $responseBody = $e->getResponse()->getBody()->getContents();
                $decodedBody = json_decode($responseBody, true);
                return [
                    'error' => true,
                    'code' => $e->getCode(),
                    'message' => $decodedBody['message'] ?? $decodedBody,
                ];
            } else {
                return [
                    'error' => true,
                    'code' => $e->getCode(),
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
                'error' => true,
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    public function capture(array $body, string $salesChannelId = '', string $idempotencyReference = ''): string|array
    {
        $this->setupClient($salesChannelId);
        $options = $this->getDefaultOptions($body, $this->buildIdempotencyKey('capture', $idempotencyReference . '|' . json_encode($body)));
        try {
            $response = $this->request(self::getEndpoint(self::TRANSACTION), $options);
            if (is_array($response) && isset($response['error'])) {
                throw new CreditCardCaptureRequestException($this->logger, json_encode($response['message']), $response['code']);
            }
            return $response->getBody()->getContents();
        } catch (CreditCardCaptureRequestException $e) {
            return [
                "error" => true,
                'code' => $e->getCode(),
                "message" => $e->getMessage()
            ];
        }
    }

    public function captureTransactionOrVoid(array $body, string $salesChannelId = '', string $idempotencyReference = ''): string|array
    {
        $this->setupClient($salesChannelId);
        $options = $this->getDefaultOptions($body, $this->buildIdempotencyKey('capture_or_void', $idempotencyReference . '|' . json_encode($body)));

        try {
            $response = $this->request(self::getEndpoint(self::CAPTURE_TRANSACTION_OR_VOID), $options);

            if (is_array($response) && isset($response['error'])) {
                throw new BaseException(
                    $this->logger,
                    json_encode($response['message']),
                    $response['code']
                );
            }

            return $response->getBody()->getContents();
        } catch (BaseException $e) {
            return [
                'error' => true,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
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
                'error' => true,
                'code' => $e->getCode(),
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
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
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
                'error' => true,
                'code' => $e->getCode(),
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
                'error' => true,
                'code' => $e->getCode(),
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
                'error' => true,
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * @return string|array<string, mixed>
     */
    public function addCardToVaultedShopper(
        string $vaultedShopperId,
        string $pfToken,
        ?string $firstName,
        ?string $lastName,
        string $salesChannelId = ''
    ): string|array {
        $body = [
            'paymentSources' => [
                'creditCardInfo' => [
                    ['pfToken' => $pfToken],
                ],
            ],
        ];

        if ($firstName !== null && $firstName !== '') {
            $body['firstName'] = $firstName;
        }

        if ($lastName !== null && $lastName !== '') {
            $body['lastName'] = $lastName;
        }

        return $this->sendVaultedShopperCardRequest($vaultedShopperId, $body, $salesChannelId);
    }

    /**
     * @return string|array<string, mixed>
     */
    public function deleteCardFromVaultedShopper(
        string $vaultedShopperId,
        string $cardType,
        string $cardLastFourDigits,
        string $salesChannelId = ''
    ): string|array {
        $body = [
            'paymentSources' => [
                'creditCardInfo' => [
                    [
                        'creditCard' => [
                            'cardType' => $cardType,
                            'cardLastFourDigits' => $cardLastFourDigits,
                        ],
                        'status' => 'D',
                    ],
                ],
            ],
        ];

        return $this->sendVaultedShopperCardRequest($vaultedShopperId, $body, $salesChannelId);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return string|array<string, mixed>
     */
    public function createVaultedShopper(array $body, string $salesChannelId = ''): string|array
    {
        $this->setupClient($salesChannelId);
        $options = $this->getDefaultOptions($body);
        try {
            $response = $this->request(self::getEndpoint(self::CREATE_VAULTED_SHOPPER), $options);
            if (is_array($response) && isset($response['error'])) {
                throw new SavedCardException($this->logger, json_encode($response['message']), $response['code']);
            }
            return $response->getBody()->getContents();
        } catch (SavedCardException $e) {
            return [
                'error' => true,
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return string|array<string, mixed>
     */
    private function sendVaultedShopperCardRequest(string $vaultedShopperId, array $body, string $salesChannelId): string|array
    {
        $this->setupClient($salesChannelId);
        $options = $this->getDefaultOptions($body);
        try {
            $response = $this->request(self::getUrlDynamicParam(self::UPDATE_SHOPPER, [$vaultedShopperId]), $options);
            if (is_array($response) && isset($response['error'])) {
                throw new SavedCardException($this->logger, json_encode($response['message']), $response['code']);
            }
            return $response->getBody()->getContents();
        } catch (SavedCardException $e) {
            return [
                'error' => true,
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    public function testConnection(string $salesChannelId): bool
    {
        try {
            $queryParam = [];

            $token = $this->makeTokenRequest($queryParam, $salesChannelId);

            if (is_array($token) && isset($token['error'])) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('BlueSnap Test Connection Error: ' . $e->getMessage());
            return false;
        }
    }

    public function refund(string $transactionId, $body, string $salesChannelId = '', string $idempotencyReference = ''): string|array
    {
        $this->setupClient($salesChannelId);
        $options = $this->getDefaultOptions($body, $this->buildIdempotencyKey('refund', $transactionId . '|' . $idempotencyReference));
        try {
            $response = $this->request(self::getUrlDynamicParam(self::REFUNDS, [$transactionId]), $options);
            if (is_array($response) && isset($response['error'])) {
                throw new RefundException($this->logger, json_encode($response['message']), $response['code']);
            }
            return $response->getBody()->getContents();
        } catch (RefundException $e) {
            return [
                'error' => true,
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    public function calculateSurcharge(array $body, string $salesChannelId = ''): string|array
    {
        $this->setupClient($salesChannelId);
        $options = $this->getDefaultOptions($body) + ['connect_timeout' => 3, 'timeout' => 8];
        try {
            $response = $this->request(self::getEndpoint(self::SURCHARGE), $options);
            if (is_array($response) && isset($response['error'])) {
                throw new BaseException(
                    $this->logger,
                    json_encode($response['message']),
                    $response['code']
                );
            }
            return $response->getBody()->getContents();
        } catch (BaseException $e) {
            return [
                'error' => true,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed>|string|null $response
     *
     * @return array{cvvResponseCode: string|null, avsResponseCode: string|null}
     */
    public static function extractVerificationCodes(array|string|null $response): array
    {
        $data = is_string($response) ? json_decode($response, true) : $response;
        if (!is_array($data)) {
            return ['cvvResponseCode' => null, 'avsResponseCode' => null];
        }

        $info = $data['processingInfo'] ?? [];
        if (!is_array($info)) {
            return ['cvvResponseCode' => null, 'avsResponseCode' => null];
        }

        $avs = [];
        foreach (['Z' => 'avsResponseCodeZip', 'A' => 'avsResponseCodeAddress', 'N' => 'avsResponseCodeName'] as $label => $key) {
            $value = $info[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $avs[] = $label . ':' . $value;
            }
        }

        $cvv = $info['cvvResponseCode'] ?? null;

        return [
            'cvvResponseCode' => is_string($cvv) && $cvv !== '' ? $cvv : null,
            'avsResponseCode' => $avs === [] ? null : implode(' ', $avs),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchTransactionData(string $transactionId, string $salesChannelId = ''): ?array
    {
        if ($transactionId === '') {
            return null;
        }

        $this->setupClient($salesChannelId);
        $options = [
            'headers' => [
                'Authorization' => 'Basic ' . $this->token,
                'Accept' => 'application/json',
            ],
        ];

        try {
            $response = $this->request(self::getUrl(self::TRANSACTION_DETAILS, $transactionId), $options);
        } catch (\Throwable $e) {
            $this->logger->error('BlueSnap transaction lookup request failed: ' . $e->getMessage());

            return null;
        }

        if (is_array($response) && isset($response['error'])) {
            return null;
        }

        $data = json_decode($response->getBody()->getContents(), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Confirms with BlueSnap that a transaction id is real, successful and matches the expected
     * amount and currency, instead of trusting a browser-supplied id and outcome.
     */
    public function verifyTransaction(string $transactionId, float $expectedAmount, string $expectedCurrency, string $salesChannelId = '', ?string $expectedMerchantTransactionId = null): bool
    {
        if ($transactionId === '') {
            return false;
        }

        $data = $this->fetchTransactionData($transactionId, $salesChannelId);

        return $this->isVerifiedTransactionData($data, $transactionId, $expectedAmount, $expectedCurrency, $expectedMerchantTransactionId);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function isVerifiedTransactionData(?array $data, string $transactionId, float $expectedAmount, string $expectedCurrency, ?string $expectedMerchantTransactionId = null): bool
    {
        if ($data === null) {
            return false;
        }

        $status = strtoupper((string) ($data['processingInfo']['processingStatus'] ?? ''));
        $cardTransactionType = strtoupper((string) ($data['cardTransactionType'] ?? ''));
        $amount = (float) ($data['amount'] ?? -1);
        $currency = strtoupper((string) ($data['currency'] ?? ''));

        if ($status !== 'SUCCESS') {
            return false;
        }

        if (!in_array($cardTransactionType, ['AUTH_CAPTURE', 'CAPTURE', 'AUTH_ONLY'], true)) {
            return false;
        }

        if (abs($amount - $expectedAmount) > 0.01) {
            return false;
        }
        if (
            $expectedMerchantTransactionId !== null
            && (string) ($data['merchantTransactionId'] ?? '') !== $expectedMerchantTransactionId
        ) {
            $this->logger->warning('BlueSnap transaction belongs to a different merchant reference', [
                'transactionId' => $transactionId,
                'expected' => $expectedMerchantTransactionId,
                'actual' => $data['merchantTransactionId'] ?? null,
            ]);

            return false;
        }

        return $currency === strtoupper($expectedCurrency);
    }
}
