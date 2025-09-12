<?php

declare(strict_types=1);

namespace solu1BluesnapPayment\Library;

abstract class Endpoints
{
    protected const PAYMENT_FIELD_TOKENS = 'PAYMENT_FIELD_TOKENS';
    protected const TRANSACTION          = 'TRANSACTION';
    PROTECTED CONST CAPTURE_TRANSACTION_OR_VOID  = 'CAPTURE_TRANSACTION_OR_VOID';
    protected const APPLE_WALLET         = 'APPLE_WALLET';
    protected const RECURRING            = 'RECURRING';
    protected const VAULTED_SHOPPERS     = 'VAULTED_SHOPPERS';
    protected const HOSTED_CHECKOUT      = 'HOSTED_CHECKOUT';
    protected const ENCRYPT_URL          = 'ENCRYPT_URL';
    protected const  UPDATE_SHOPPER      = 'UPDATE_SHOPPER';
    protected const REFUNDS              = 'REFUNDS';

    private static array $endpoints = [
      self::PAYMENT_FIELD_TOKENS => [
        'method' => 'POST',
        'url'    => '/services/2/payment-fields-tokens'
      ],
      self::TRANSACTION => [
        'method' => 'POST',
        'url'    => '/services/2/transactions'
      ],

      self::CAPTURE_TRANSACTION_OR_VOID => [
        'method' => 'PUT',
        'url'    => '/services/2/transactions'
      ],

      self::APPLE_WALLET => [
        'method' => 'POST',
        'url'    => '/services/2/wallets'
      ],
      self::VAULTED_SHOPPERS => [
        'method' => 'GET',
        'url'    => '/services/2/vaulted-shoppers'
      ],
      self::HOSTED_CHECKOUT => [
        'method' => 'POST',
        'url'    => '/services/2/bn3-services/jwt'
      ],
      self::ENCRYPT_URL => [
        'method' => 'POST',
        'url'    => '/services/2/tools/param-encryption'
      ],
      self::UPDATE_SHOPPER => [
        'method' => 'PUT',
        'url'    => '/services/2/vaulted-shoppers'
      ],
      self::REFUNDS => [
        'method' => 'POST',
        'url'    => '/services/2/transactions/refund'
      ]
    ];

    protected static function getEndpoint(string $endpoint): array
    {
        return self::$endpoints[$endpoint];
    }

    public static function getUrl(string $endpoint, ?string $id = null): array
    {
        $endpointDetails = self::getEndpoint($endpoint);
        $baseUrl         = $endpointDetails['url'];
        return [
          'method' => $endpointDetails['method'],
          'url'    => $id ? $baseUrl . '/' . $id : $baseUrl
        ];
    }

    public static function getUrlDynamicParam(string $endpoint, ?array $params = [], ?array $queryParam = []): array
    {
        $endpointDetails = self::getEndpoint($endpoint);
        $baseUrl         = $endpointDetails['url'];
        $paramBuilder    = '';
        foreach ($params as $value) {
            $paramBuilder .= '/' . $value;
        }

        if (!empty($queryParam)) {
            $queryString = '?' . http_build_query($queryParam);
        } else {
            $queryString = '';
        }

        return [
          'method' => $endpointDetails['method'],
          'url'    => $baseUrl . $paramBuilder . $queryString
        ];
    }
}
