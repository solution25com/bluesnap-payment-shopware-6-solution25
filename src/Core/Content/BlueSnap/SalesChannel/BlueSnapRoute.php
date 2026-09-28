<?php

namespace BlueSnap\Core\Content\BlueSnap\SalesChannel;

use BlueSnap\Core\Content\Transaction\BluesnapTransactionEntity;
use BlueSnap\PaymentMethods\PaymentMethods;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Payment\SalesChannel\HandlePaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\HandlePaymentMethodRouteResponse;
use BlueSnap\Core\Checkout\Cart\BlueSnapSurchargeContext;
use BlueSnap\Core\Content\BlueSnap\AbstractBlueSnapRoute;
use BlueSnap\Core\Content\BlueSnap\BlueSnapApiResponseStruct;
use BlueSnap\Core\Content\VaultedShopper\SavedCardStruct;
use BlueSnap\Exceptions\SavedCardException;
use BlueSnap\Library\Constants\TransactionStatuses;
use BlueSnap\Library\ValidatorUtility;
use BlueSnap\Library\CardHolderInfo;
use BlueSnap\Service\BlueSnapApiClient;
use BlueSnap\Service\BlueSnapConfig;
use BlueSnap\Service\BlueSnapTransactionService;
use BlueSnap\Service\OrderService;
use BlueSnap\Service\PaymentLinkService;
use BlueSnap\Service\RefundService;
use BlueSnap\Service\SavedCardService;
use BlueSnap\Service\VaultedShopperService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

#[Route(defaults: ["_routeScope" => ['store-api'], "_loginRequired" => true, "_loginRequiredAllowGuest" => true])]
class BlueSnapRoute extends AbstractBlueSnapRoute
{
    private BlueSnapApiClient $blueSnapClient;
    private BlueSnapConfig $blueSnapConfig;
    private ValidatorUtility $validator;
    private VaultedShopperService $vaultedShopperService;
    private OrderService $orderService;
    private PaymentLinkService $paymentLinkService;
    private BlueSnapTransactionService $blueSnapTransactionService;
    private RefundService $refundService;
    private OrderTransactionStateHandler $transactionStateHandler;
    private HandlePaymentMethodRoute $handlePaymentMethodRoute;
    private LoggerInterface $logger;
    private BlueSnapSurchargeContext $surchargeContext;
    private SavedCardService $savedCardService;
    private CartService $cartService;

    public function __construct(
        BlueSnapApiClient $client,
        BlueSnapConfig $blueSnapConfig,
        ValidatorUtility $validator,
        VaultedShopperService $vaultedShopperService,
        OrderService $orderService,
        PaymentLinkService $paymentLinkService,
        BlueSnapTransactionService $blueSnapTransactionService,
        RefundService $refundService,
        OrderTransactionStateHandler $transactionStateHandler,
        HandlePaymentMethodRoute $handlePaymentMethodRoute,
        LoggerInterface $logger,
        BlueSnapSurchargeContext $surchargeContext,
        SavedCardService $savedCardService,
        CartService $cartService,
    ) {
        $this->blueSnapClient = $client;
        $this->blueSnapConfig = $blueSnapConfig;
        $this->validator = $validator;
        $this->vaultedShopperService = $vaultedShopperService;
        $this->orderService = $orderService;
        $this->paymentLinkService = $paymentLinkService;
        $this->blueSnapTransactionService = $blueSnapTransactionService;
        $this->refundService = $refundService;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->handlePaymentMethodRoute = $handlePaymentMethodRoute;
        $this->logger = $logger;
        $this->surchargeContext = $surchargeContext;
        $this->savedCardService = $savedCardService;
        $this->cartService = $cartService;
    }

    /**
     * The cart is the only authority on what a capture may charge; its total already carries any
     * surcharge. A request `amount` is never trusted.
     */
    private function resolveServerAmount(SalesChannelContext $context): float
    {
        $cart = $this->cartService->getCart($context->getToken(), $context);

        return round($cart->getPrice()->getTotalPrice(), 2);
    }

    public function getDecorated(): AbstractBlueSnapRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(path: '/store-api/bluesnap/get-pf-token', name: 'store-api.bluesnap.getPfToken', methods: ['GET'])]
    public function getPfToken(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $response = $this->blueSnapClient->makeTokenRequest([], $context->getSalesChannelId());
        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $response));
    }

    #[Route(path: '/store-api/bluesnap/refund', name: 'store-api.bluesnap.refund', methods: ['POST'])]
    public function refund(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $data = $request->request->all();

        $errors = $this->validateRefundInput($data);
        if (count($errors) > 0) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
        }

        $customer = $context->getCustomer();
        $order = $this->orderService->getOrderDetailsById($data['orderId'], $context->getContext());
        $orderCustomerId = $order?->getOrderCustomer()?->getCustomerId();
        if (!$customer || !$order || $orderCustomerId !== $customer->getId()) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'Not found'), 404);
        }

        $response = $this->refundService->handelRefunds($data, $context->getContext());

        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $response));
    }

    /**
     * Admin-initiated refund. Access is gated by the `order.editor` ACL on the /api/refund route.
     */
    public function adminRefund(Request $request, Context $context): BlueSnapApiResponse
    {
        $data = $request->request->all();

        $errors = $this->validateRefundInput($data);
        if (count($errors) > 0) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
        }

        $response = $this->refundService->handelRefunds($data, $context);

        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $response));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<int, mixed>
     */
    private function validateRefundInput(array $data): array
    {
        $constraints = new Assert\Collection([
            'orderId' => [new Assert\NotBlank(), new Assert\Type('string')],
            'returnId' => [new Assert\NotBlank(), new Assert\Type('string')],
        ]);

        return $this->validator->validateFields($data, $constraints);
    }

    #[Route(path: '/store-api/bluesnap/calculate-surcharge', name: 'store-api.bluesnap.calculateSurcharge', methods: ['POST'])]
    public function calculateSurcharge(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $request->request->set('data', $data);
        $constraints = new Assert\Collection([
        'pfToken' => new Assert\Optional([new Assert\Type('string')]),
        'cardKey' => new Assert\Optional([new Assert\Type('string')]),
        'amount'  => [new Assert\NotBlank()],
        'cardType' => new Assert\Optional([new Assert\Type('string')]),
        ]);

        $errors = $this->validator->validateFields($data, $constraints);
        if (count($errors) > 0) {
            return new BlueSnapApiResponse(
                new BlueSnapApiResponseStruct(false, $errors),
                400
            );
        }

        $salesChannelId = $context->getSalesChannelId();
        $pfToken = (string) ($data['pfToken'] ?? '');
        $cardKey = (string) ($data['cardKey'] ?? '');
        $cartAmount = round((float) ($data['amount'] ?? 0), 2);

        if ($pfToken === '' && $cardKey === '') {
            return new BlueSnapApiResponse(
                new BlueSnapApiResponseStruct(false, 'Either a payment field token or a saved card is required'),
                400
            );
        }

        $body = [
            'currency' => $context->getCurrency()->getIsoCode(),
            'amount' => (string) $cartAmount,
            'paymentMethod' => 'CC',
        ];

        if ($cardKey !== '') {
            $vaultedShopperId = $this->vaultedShopperService->getVaultedShopperIdByCustomerId(
                $context->getContext(),
                (string) $context->getCustomer()?->getId()
            );

            $cardSelector = $this->savedCardService->getCardSelector(
                $context->getCustomer(),
                $cardKey,
                $salesChannelId,
                $context->getContext()
            );

            if ($vaultedShopperId === null || $cardSelector === null) {
                return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'Card not found'), 404);
            }

            $body['vaultedShopperId'] = $vaultedShopperId;
            $body['creditCard'] = $cardSelector;
        } else {
            $body['pfToken'] = $pfToken;
        }

        $res = $this->blueSnapClient->calculateSurcharge($body, $salesChannelId);
        $request->request->set('res', $res);


        if (is_array($res) && isset($res['error'])) {
            return new BlueSnapApiResponse(
                new BlueSnapApiResponseStruct(false, $res),
                400
            );
        }

        $decoded = is_string($res) ? json_decode($res, true) : $res;
        if (!is_array($decoded)) {
            return new BlueSnapApiResponse(
                new BlueSnapApiResponseStruct(false, 'Invalid response'),
                500
            );
        }

        if ($pfToken !== '') {
            $this->surchargeContext->setPfToken($pfToken);
            $this->surchargeContext->clearVaultedCustomerId();
            $this->surchargeContext->setSelectedCardKey(BlueSnapSurchargeContext::NEW_CARD);

            $cardType = (string) ($data['cardType'] ?? '');
            if ($cardType !== '') {
                $this->surchargeContext->setCardType($cardType);
            }
        } else {
            $this->surchargeContext->setSelectedCardKey($cardKey);
        }

        $surchargeInfo = $decoded['surchargeInfo'] ?? $decoded;
        $amount = (float) ($surchargeInfo['surchargeAmount'] ?? 0);
        $token = (string) ($surchargeInfo['surchargeToken'] ?? '');
        $reference = $surchargeInfo['surchargeReference'] ?? null;

        $quotedCard = $decoded['creditCard'] ?? [];
        if ($token === '') {
            $this->surchargeContext->clearSurchargeData();

            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $decoded));
        }

        $this->surchargeContext->setSurchargeData([
            'bluesnap_surcharge_amount' => $amount,
            'bluesnap_surcharge_token' => $token,
            'bluesnap_surcharge_reference' => $reference,
            'bluesnap_surcharge_base_amount' => $cartAmount,
            'bluesnap_surcharge_pfToken' => $pfToken !== '' ? $pfToken : null,
            'bluesnap_surcharge_card_key' => $cardKey !== '' ? $cardKey : null,
            'bluesnap_surcharge_quoted_card_type' => $quotedCard['cardType'] ?? null,
            'bluesnap_surcharge_quoted_last_four' => $quotedCard['cardLastFourDigits'] ?? null,
            'bluesnap_surcharge_quoted_sub_type' => $quotedCard['cardSubType'] ?? null,
        ]);

        return new BlueSnapApiResponse(
            new BlueSnapApiResponseStruct(true, $decoded)
        );
    }

    #[Route(path: '/store-api/bluesnap/capture', name: 'store-api.bluesnap.capture', methods: ['POST'])]
    public function capture(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $salesChannelId = $context->getSalesChannel()->getId();
        $is3DSEnabled = $this->blueSnapConfig->getConfig('threeDS', $salesChannelId);
        $data = $request->request->all();

        $cardTransactionType = $this->blueSnapConfig->getCardTransactionType($salesChannelId);

        $customer = $context->getCustomer();
        $billingAddress = $customer->getActiveBillingAddress() ?? $customer->getDefaultBillingAddress();
        $email = $customer->getEmail();

        $constraints = new Assert\Collection([
            'pfToken' => [new Assert\NotBlank(), new Assert\Type('string')],
            'firstName' => [new Assert\NotBlank(), new Assert\Type('string')],
            'lastName' => [new Assert\NotBlank(), new Assert\Type('string')],
            'amount' => [new Assert\NotBlank(), new Assert\Type('string')],
            'saveCard' => [
                new Assert\Optional([
                    new Assert\Type('bool'),
                ])
            ],
            'cardType' => [new Assert\Optional([new Assert\Type('string')])],
            'lastFourDigits' => [new Assert\Optional([new Assert\Type('string')])],
            'authResult' => [
                new Assert\Optional([
                    new Assert\Type('string'),
                ])
            ],
            'threeDSecureReferenceId' => [
                new Assert\Optional([
                    new Assert\Type('string'),
                ])
            ],
            'cartData' => new Assert\Optional([
                new Assert\Type('array'),
            ]),
            'surchargeToken' => new Assert\Optional([
                new Assert\Type('string'),
            ]),
          'surchargeAmount' => new Assert\Optional([
            new Assert\Type('string'),
            ]),
        ]);

        $errors = $this->validator->validateFields($data, $constraints);
        if (count($errors) > 0) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
        }

        $threeDSError = $this->assert3DSecureSatisfied($data, (bool) $is3DSEnabled);
        if ($threeDSError) {
            return $threeDSError;
        }

        $amount = $this->resolveServerAmount($context);

        $saveCard = !empty($data['saveCard']) && $customer !== null && !$customer->getGuest();
        $existingVaultedShopperId = $saveCard
            ? $this->vaultedShopperService->getVaultedShopperIdByCustomerId($context->getContext(), $customer->getId())
            : null;

        $body = [
            "amount" => $amount,
            "softDescriptor" => "Card Capture",
            "currency" => $context->getCurrency()->getIsoCode(),
            "cardHolderInfo" => CardHolderInfo::build(
                $billingAddress,
                (string) $data['firstName'],
                (string) $data['lastName'],
                $email
            ),
            "pfToken" => $data['pfToken'],
            "cardTransactionType" => $cardTransactionType,
            "transactionInitiator" => "SHOPPER"
        ];

        if ($existingVaultedShopperId !== null) {
            $body['vaultedShopperId'] = $existingVaultedShopperId;
        }


        if ($is3DSEnabled && !empty($data['threeDSecureReferenceId'])) {
            $body['threeDSecure'] = [
                "authResult" => $data['authResult'],
                "threeDSecureReferenceId" => $data['threeDSecureReferenceId']
            ];
        }

        if (!empty($data['cartData'])) {
            $level3Data = $this->orderService->buildLevel3Data($data['cartData'], $context->getContext());
            if (!empty($level3Data)) {
                $body['level3Data'] = $level3Data;
            }
        }

        $surchargeToken = $this->quotedSurchargeToken();
        if ($surchargeToken !== '') {
            $body['surchargeInfo'] = [
                'surchargeToken' => $surchargeToken,
            ];
        }
        $response = $this->blueSnapClient->capture($body, $salesChannelId, $context->getToken());

        if (isset($response['error'])) {
            $this->logCaptureFailure('capture', $body, $response);

            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $response['message']), $response['code']);
        }

        $responseData = json_decode($response, true);
        $vaultedShopperId = is_array($responseData) ? ($responseData['vaultedShopperId'] ?? null) : null;
        if ($saveCard && $vaultedShopperId) {
            $cardType = !empty($data['cardType']) ? $data['cardType'] : 'CREDIT';
            $this->vaultedShopperService->store((string) $vaultedShopperId, $cardType, $customer->getId(), $context->getContext());
            $this->surchargeContext->setVaultedCustomerId((string) $vaultedShopperId);
        }

        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $response));
    }

    private function quotedSurchargeToken(): string
    {
        $quote = $this->surchargeContext->getSurchargeData();

        return is_array($quote) ? (string) ($quote['bluesnap_surcharge_token'] ?? '') : '';
    }

    #[Route(path: '/store-api/bluesnap/google-capture', name: 'store-api.bluesnap.googleCapture', methods: ['POST'])]
    public function googleCapture(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $data = $request->request->all();
        $cardTransactionType = $this->blueSnapConfig->getCardTransactionType($context->getSalesChannelId());
        $constraints = new Assert\Collection([
            'gToken' => [new Assert\NotBlank(), new Assert\Type('string')],
            'amount' => [new Assert\NotBlank(), new Assert\Type('string')],
            'email' => [new Assert\NotBlank(), new Assert\Type('string')],
            'surchargeToken' => new Assert\Optional([
                new Assert\Type('string'),
            ]),
            'cartData' => new Assert\Optional([
                new Assert\Type('array'),
            ]),

        ]);

        $errors = $this->validator->validateFields($data, $constraints);

        if (count($errors) > 0) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
        }

        $body = [
            "amount" => $this->resolveServerAmount($context),
            "softDescriptor" => "Google Pay",
            "currency" => $context->getCurrency()->getIsoCode(),
            "cardTransactionType" => $cardTransactionType,
            "wallet" => [
                "walletType" => "GOOGLE_PAY",
                "encodedPaymentToken" => $data['gToken'],
            ],
            "cardHolderInfo" => [
                "email" => $data['email'],
            ]
        ];

        $salesChannelId = $context->getSalesChannel()->getId();

        if (!empty($data['cartData'])) {
            $level3Data = $this->orderService->buildLevel3Data($data['cartData'], $context->getContext());
            if (!empty($level3Data)) {
                $body['level3Data'] = $level3Data;
            }
        }
        if (!empty($data['surchargeToken'])) {
            $body['surchargeInfo'] = [
                'surchargeToken' => $data['surchargeToken'],
            ];
        }

        $response = $this->blueSnapClient->capture($body, $salesChannelId, $context->getToken());

        if (isset($response['error'])) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $response['message']), $response['code']);
        }
        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $response));
    }

    #[Route(path: '/store-api/bluesnap/apple-capture', name: 'store-api.bluesnap.appleCapture', methods: ['POST'])]
    public function appleCapture(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $data = $request->request->all();
        $cardTransactionType = $this->blueSnapConfig->getCardTransactionType($context->getSalesChannelId());

        $customerEmail = $context->getCustomer()->getEmail();
        $constraints = new Assert\Collection([
            'appleToken' => [new Assert\NotBlank(), new Assert\Type('string')],
            'amount' => [new Assert\NotBlank(), new Assert\Type('string')],
            'surchargeToken' => new Assert\Optional([
                new Assert\Type('string'),
            ]),
            'cartData' => new Assert\Optional([
                new Assert\Type('array'),
            ]),
            'email' => new Assert\Optional([
                new Assert\Type('string'),
            ]),
        ]);

        $errors = $this->validator->validateFields($data, $constraints);
        if (count($errors) > 0) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
        }
        $body = [
            "amount" => $this->resolveServerAmount($context),
            "softDescriptor" => "Apple Pay",
            "currency" => $context->getCurrency()->getIsoCode(),
            "cardTransactionType" => "$cardTransactionType",
            "wallet" => [
                "walletType" => "APPLE_PAY",
                "encodedPaymentToken" => $data['appleToken'],
            ],
            "cardHolderInfo" => [
                "email" => $customerEmail
            ]];


        if (!empty($data['cartData'])) {
            $level3Data = $this->orderService->buildLevel3Data($data['cartData'], $context->getContext());
            if (!empty($level3Data)) {
                $body['level3Data'] = $level3Data;
            }
        }
        if (!empty($data['surchargeToken'])) {
            $body['surchargeInfo'] = [
                'surchargeToken' => $data['surchargeToken'],
            ];
        }

        $response = $this->blueSnapClient->capture($body, $context->getSalesChannelId(), $context->getToken());
        if (isset($response['error'])) {
            $this->logger->error(sprintf('Error capturing payment: %s', $response['message']));
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $response['message']), $response['code']);
        }
        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $response));
    }

    #[Route(path: '/store-api/bluesnap/apple-create-wallet', name: 'store-api.bluesnap.appleCreateWallet', methods: ['POST'])]
    public function appleCreateWallet(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $data = $request->request->all();
        $constraints = new Assert\Collection([
            'validationUrl' => [new Assert\NotBlank(), new Assert\Type('string')],
            'domainName' => [new Assert\NotBlank(), new Assert\Type('string')],
            'displayName' => [new Assert\NotBlank(), new Assert\Type('string')],
        ]);

        $errors = $this->validator->validateFields($data, $constraints);
        if (count($errors) > 0) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
        }

        $response = $this->blueSnapClient->appleWalletRequest([
            "walletType" => "APPLE_PAY",
            "validationUrl" => $data["validationUrl"],
            "domainName" => $data["domainName"],
            "displayName" => $data["displayName"],
        ], $context->getSalesChannelId());
        if (isset($response['error'])) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $response['message']), $response['code']);
        }
        $responseBody = trim($response);
        $responseBody = preg_replace('/^\xEF\xBB\xBF/', '', $responseBody);
        $decodedData = json_decode($responseBody, true);
        $base64Decoded = base64_decode($decodedData['walletToken']);

        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $base64Decoded));
    }

    #[Route(path: '/store-api/bluesnap/get-config', name: 'store-api.bluesnap.getBluesnapConfig', defaults: ["_loginRequired" => false], methods: ['GET'])]
    public function getBluesnapConfig(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $salesChannelId = $context->getSalesChannel()->getId();
        $config = [
            'mode' => $this->blueSnapConfig->getConfig('mode', $salesChannelId),
            'merchantId' => $this->blueSnapConfig->getConfig('merchantId', $salesChannelId),
            '3D' => $this->blueSnapConfig->getConfig('threeDS', $salesChannelId) ?? false,
            'merchantGoogleId' => $this->blueSnapConfig->getConfig('merchantGoogleId', $salesChannelId),
        ];
        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $config));
    }

    private function assert3DSecureSatisfied(array $data, bool $is3DSEnabled): ?BlueSnapApiResponse
    {
        if (!$is3DSEnabled) {
            return null;
        }

        if (!self::isAcceptable3DSecureResult((string) ($data['authResult'] ?? ''))) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, '3D Secure authentication is required'), 400);
        }

        return null;
    }

    public static function isAcceptable3DSecureResult(string $authResult): bool
    {
        return in_array($authResult, [
            'AUTHENTICATION_SUCCEEDED',
            'AUTHENTICATION_BYPASSED',
            'AUTHENTICATION_UNAVAILABLE',
        ], true);
    }

    /**
     * A Store API customer context does not prove the caller owns the order, so every route that
     * acts on an order id checks it explicitly.
     */
    private function assertOrderOwnership(?string $orderId, SalesChannelContext $context): ?BlueSnapApiResponse
    {
        $customer = $context->getCustomer();
        if ($customer === null || $orderId === null || $orderId === '') {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'Not found'), 404);
        }

        $order = $this->orderService->getOrderDetailsById($orderId, $context->getContext());
        if ($order === null || $order->getOrderCustomer()?->getCustomerId() !== $customer->getId()) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'Not found'), 404);
        }

        return null;
    }

    private function assertVaultOwnership(string $vaultedId, SalesChannelContext $context): ?BlueSnapApiResponse
    {
        $customer = $context->getCustomer();
        if (!$customer) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'Not found'), 404);
        }

        $ownedVaultedId = $this->vaultedShopperService->getVaultedShopperIdByCustomerId($context->getContext(), $customer->getId());
        if ($ownedVaultedId === null || !hash_equals($ownedVaultedId, $vaultedId)) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'Not found'), 404);
        }

        return null;
    }

    #[Route(path: '/store-api/bluesnap/vaulted-shopper', name: 'store-api.bluesnap.vaultedShopper', methods: ['POST'])]
    public function vaultedShopper(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $salesChannelId = $context->getSalesChannel()->getId();
        $cardTransactionType = $this->blueSnapConfig->getCardTransactionType($context->getSalesChannelId());


        $is3DSEnabled = $this->blueSnapConfig->getConfig('threeDS', $salesChannelId);
        $data = $request->request->all();
        $constraints = new Assert\Collection([
            'pfToken' => [new Assert\NotBlank(), new Assert\Type('string')],
            'vaultedId' => [new Assert\NotBlank(), new Assert\Type('string')],
            'amount' => [new Assert\NotBlank(), new Assert\Type('string')],
            'cardKey' => new Assert\Optional([
                new Assert\Type('string'),
            ]),
            'authResult' => [
                new Assert\Optional([
                    new Assert\Type('string'),
                ])
            ],
            'threeDSecureReferenceId' => [
                new Assert\Optional([
                    new Assert\Type('string'),
                ])
            ],
            'cartData' => new Assert\Optional([
                new Assert\Type('array'),
            ]),
            'surchargeToken' => new Assert\Optional([
                new Assert\Type('string'),
            ]),
            'surchargeAmount' => new Assert\Optional([
                new Assert\Type('string'),
            ]),
        ]);

        $errors = $this->validator->validateFields($data, $constraints);
        if (count($errors) > 0) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
        }

        $threeDSError = $this->assert3DSecureSatisfied($data, (bool) $is3DSEnabled);
        if ($threeDSError) {
            return $threeDSError;
        }

        $vaultedId = $data['vaultedId'];

        $ownershipError = $this->assertVaultOwnership($vaultedId, $context);
        if ($ownershipError) {
            return $ownershipError;
        }


        $body = [
            "amount" => $this->resolveServerAmount($context),
            "vaultedShopperId" => $vaultedId,
            "softDescriptor" => "DescTest",
            "currency" => $context->getCurrency()->getIsoCode(),
            "cardTransactionType" => $cardTransactionType,
        ];

        $cardKey = (string) ($data['cardKey'] ?? '');
        if ($cardKey !== '') {
            $cardSelector = $this->savedCardService->getCardSelector(
                $context->getCustomer(),
                $cardKey,
                $salesChannelId,
                $context->getContext()
            );

            if ($cardSelector === null) {
                return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'Selected card not found'), 404);
            }

            $body['creditCard'] = $cardSelector;
        }

        if ($is3DSEnabled && !empty($data['threeDSecureReferenceId'])) {
            $body['threeDSecure'] = [
                "authResult" => $data['authResult'],
                "threeDSecureReferenceId" => $data['threeDSecureReferenceId']
            ];
        }

        if (!empty($data['cartData'])) {
            $level3Data = $this->orderService->buildLevel3Data($data['cartData'], $context->getContext());
            if (!empty($level3Data)) {
                $body['level3Data'] = $level3Data;
            }
        }

        $surchargeToken = $this->quotedSurchargeToken();
        if ($surchargeToken !== '') {
            $body['surchargeInfo'] = [
                'surchargeToken' => $surchargeToken,
            ];
        }

        $response = $this->blueSnapClient->capture($body, $context->getSalesChannelId(), $context->getToken());
        if (isset($response['error'])) {
            $this->logCaptureFailure('vaultedShopper', $body, $response);

            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $response['message']), $response['code']);
        }

        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $response));
    }

    #[Route(path: '/store-api/bluesnap/vaulted-shopper-data/{vaultedShopperId}', name: 'store-api.bluesnap.vaultedShopperData', methods: ['GET'])]
    public function vaultedShopperData(string $vaultedShopperId, Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $ownershipError = $this->assertVaultOwnership($vaultedShopperId, $context);
        if ($ownershipError) {
            return $ownershipError;
        }

        $vaultedShopperData = $this->blueSnapClient->getVaultedShopper($vaultedShopperId, $context->getSalesChannelId());
        if (isset($vaultedShopperData['error'])) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $vaultedShopperData['message']), $vaultedShopperData['code']);
        }
        if (!$vaultedShopperData) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, "error fetching shopper data"), 400);
        }
        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $vaultedShopperData));
    }

    /**
     * @deprecated tag:v2.0.0 - use store-api.bluesnap.savedCards.remove
     */
    #[Route(path: '/store-api/bluesnap/update-vaulted-shopper/{vaultedShopperId}', name: 'store-api.bluesnap.updateVaultedShopper', methods: ['PUT'])]
    public function updateVaultedShopper(string $vaultedShopperId, Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $ownershipError = $this->assertVaultOwnership($vaultedShopperId, $context);
        if ($ownershipError) {
            return $ownershipError;
        }

        $data = $request->request->all();
        $constraints = new Assert\Collection([
            'pfToken' => [new Assert\NotBlank(), new Assert\Type('string')],
            'firstName' => [new Assert\NotBlank(), new Assert\Type('string')],
            'lastName' => [new Assert\NotBlank(), new Assert\Type('string')],
            'cardType' => [new Assert\NotBlank(), new Assert\Type('string')],
            'cardLastFourDigits' => [new Assert\NotBlank(), new Assert\Type('string')],
        ]);

        $errors = $this->validator->validateFields($data, $constraints);
        if (count($errors) > 0) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
        }

        $cardKey = SavedCardStruct::createCardKey($data['cardType'], $data['cardLastFourDigits']);

        return $this->removeSavedCard($cardKey, $request, $context);
    }

    #[Route(
        path: '/store-api/bluesnap/saved-cards',
        name: 'store-api.bluesnap.savedCards.list',
        defaults: ['_loginRequired' => true, '_loginRequiredAllowGuest' => false],
        methods: ['GET']
    )]
    public function listSavedCards(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $cards = $this->savedCardService->getCards(
            $context->getCustomer(),
            $context->getSalesChannelId(),
            $context->getContext()
        );

        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $cards));
    }

    #[Route(
        path: '/store-api/bluesnap/saved-cards/token',
        name: 'store-api.bluesnap.savedCards.token',
        defaults: ['_loginRequired' => true, '_loginRequiredAllowGuest' => false],
        methods: ['POST']
    )]
    public function createSavedCardToken(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        try {
            $token = $this->savedCardService->createAddCardToken(
                $context->getCustomer(),
                $context->getSalesChannelId(),
                $context->getContext()
            );
        } catch (SavedCardException $e) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $e->getMessage()), $this->resolveStatusCode($e));
        }

        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $token));
    }

    #[Route(
        path: '/store-api/bluesnap/saved-cards',
        name: 'store-api.bluesnap.savedCards.add',
        defaults: ['_loginRequired' => true, '_loginRequiredAllowGuest' => false],
        methods: ['POST']
    )]
    public function addSavedCard(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $data = $request->request->all();
        $constraints = new Assert\Collection([
            'pfToken' => [new Assert\NotBlank(), new Assert\Type('string')],
        ]);

        $errors = $this->validator->validateFields($data, $constraints);
        if (count($errors) > 0) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
        }

        try {
            $card = $this->savedCardService->addCard(
                $context->getCustomer(),
                $data['pfToken'],
                $context->getSalesChannelId(),
                $context->getContext()
            );
        } catch (SavedCardException $e) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $e->getMessage()), $this->resolveStatusCode($e));
        }

        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $card));
    }

    #[Route(
        path: '/store-api/bluesnap/saved-cards/{cardKey}',
        name: 'store-api.bluesnap.savedCards.remove',
        requirements: ['cardKey' => '[0-9a-f]{32}'],
        defaults: ['_loginRequired' => true, '_loginRequiredAllowGuest' => false],
        methods: ['DELETE']
    )]
    public function removeSavedCard(string $cardKey, Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        try {
            $removed = $this->savedCardService->removeCard(
                $context->getCustomer(),
                $cardKey,
                $context->getSalesChannelId(),
                $context->getContext()
            );
        } catch (SavedCardException $e) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $e->getMessage()), $this->resolveStatusCode($e));
        }

        if (!$removed) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'Card not found'), 404);
        }

        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, ['cardKey' => $cardKey]));
    }

    #[Route(
        path: '/store-api/bluesnap/saved-cards/{cardKey}/preferred',
        name: 'store-api.bluesnap.savedCards.preferred',
        requirements: ['cardKey' => '[0-9a-f]{32}'],
        defaults: ['_loginRequired' => true, '_loginRequiredAllowGuest' => false],
        methods: ['POST']
    )]
    public function setPreferredSavedCard(string $cardKey, Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        if (!$this->savedCardService->isEnabled($context->getSalesChannelId())) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'Saved cards are disabled for this sales channel'), 403);
        }

        $updated = $this->savedCardService->setPreferredCard(
            $context->getCustomer(),
            $cardKey,
            $context->getSalesChannelId(),
            $context->getContext()
        );

        if (!$updated) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'Card not found'), 404);
        }

        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, ['cardKey' => $cardKey]));
    }

    /**
     * Records which saved card the customer picked at checkout. The surcharge is calculated per card
     * by the cart collector, so the choice has to reach the server before the cart is recalculated.
     * An empty card key means the customer chose to pay with a new card.
     */
    #[Route(
        path: '/store-api/bluesnap/saved-cards/select',
        name: 'store-api.bluesnap.savedCards.select',
        defaults: ['_loginRequired' => true, '_loginRequiredAllowGuest' => false],
        methods: ['POST']
    )]
    public function selectSavedCard(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $data = $request->request->all();
        $constraints = new Assert\Collection([
            'cardKey' => [new Assert\Type('string'), new Assert\Regex('/^([0-9a-f]{32})?$/')],
        ]);

        $errors = $this->validator->validateFields($data, $constraints);
        if (count($errors) > 0) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
        }

        $cardKey = (string) ($data['cardKey'] ?? '');
        $this->surchargeContext->clearSurchargeData();

        if ($cardKey === '') {
            $this->surchargeContext->setSelectedCardKey(BlueSnapSurchargeContext::NEW_CARD);
            $this->surchargeContext->clearVaultedCustomerId();

            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, ['cardKey' => '']));
        }

        $card = $this->savedCardService->findCardByKey(
            $context->getCustomer(),
            $cardKey,
            $context->getSalesChannelId(),
            $context->getContext()
        );

        if ($card === null) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'Card not found'), 404);
        }

        $this->surchargeContext->clearPfToken();
        $this->surchargeContext->setSelectedCardKey($cardKey);

        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, ['cardKey' => $cardKey]));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $response
     */
    private function logCaptureFailure(string $route, array $body, array $response): void
    {
        $surchargeData = $this->surchargeContext->getSurchargeData();

        $this->logger->error('BlueSnap capture API error', [
            'route' => $route,
            'response' => $response,
            'request' => [
                'amount' => $body['amount'] ?? null,
                'currency' => $body['currency'] ?? null,
                'cardTransactionType' => $body['cardTransactionType'] ?? null,
                'creditCard' => $body['creditCard'] ?? null,
                'hasVaultedShopper' => isset($body['vaultedShopperId']),
                'hasPfToken' => isset($body['pfToken']),
                'hasSurchargeToken' => isset($body['surchargeInfo']),
                'hasThreeDSecure' => isset($body['threeDSecure']),
            ],
            'storedQuote' => is_array($surchargeData) ? [
                'amount' => $surchargeData['bluesnap_surcharge_amount'] ?? null,
                'baseAmount' => $surchargeData['bluesnap_surcharge_base_amount'] ?? null,
                'cardKey' => $surchargeData['bluesnap_surcharge_card_key'] ?? null,
                'quotedForPfToken' => !empty($surchargeData['bluesnap_surcharge_pfToken']),
                'quotedCardType' => $surchargeData['bluesnap_surcharge_quoted_card_type'] ?? null,
                'quotedLastFour' => $surchargeData['bluesnap_surcharge_quoted_last_four'] ?? null,
                'quotedSubType' => $surchargeData['bluesnap_surcharge_quoted_sub_type'] ?? null,
                'samePfToken' => ($surchargeData['bluesnap_surcharge_pfToken'] ?? null)
                    === ($body['pfToken'] ?? null),
                'sameToken' => ($surchargeData['bluesnap_surcharge_token'] ?? null)
                    === ($body['surchargeInfo']['surchargeToken'] ?? null),
            ] : null,
        ]);
    }

    private function resolveStatusCode(SavedCardException $exception): int
    {
        $code = (int) $exception->getCode();

        return $code >= 400 && $code < 600 ? $code : 400;
    }

    #[Route(path: '/store-api/bluesnap/hosted-pages-link', name: 'store-api.bluesnap.hostedPagesLink', methods: ['POST'])]
    public function hostedPagesLink(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $data = $request->request->all();

        $constraints = new Assert\Collection([
            'order_id' => [new Assert\NotBlank(), new Assert\Type('string')],
            'successUrl' => [new Assert\NotBlank(), new Assert\Type('string')],
            'failedUrl' => [new Assert\NotBlank(), new Assert\Type('string')],
            'paymentMethod' => [new Assert\NotBlank(), new Assert\Type('string')],
        ]);

        $errors = $this->validator->validateFields($data, $constraints);
        if (count($errors) > 0) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
        }

        $orderDetail = $this->orderService->getOrderDetailsById($data['order_id'], $context->getContext());
        $customer = $context->getCustomer();
        $orderCustomerId = $orderDetail?->getOrderCustomer()?->getCustomerId();
        if (!$customer || !$orderDetail || $orderCustomerId !== $customer->getId()) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'Not found'), 404);
        }

        $successUrl = $data['successUrl'] . '?orderId=' . $data['order_id'];
        $failedUrl = $data['failedUrl'];

        $this->blueSnapTransactionService->addTransaction($data['order_id'], $data['paymentMethod'], $data['order_id'], TransactionStatuses::PENDING->value, $context->getContext(), null, OrderService::latestTransaction($orderDetail)?->getId());
        $link = $this->paymentLinkService->generatePaymentLink($orderDetail, $successUrl, $failedUrl, $context->getContext(), true, $context->getSalesChannelId());

        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $link));
    }

    #[Route(path: '/store-api/bluesnap/create-transaction', name: 'store-api.bluesnap.createTransaction', methods: ['POST'])]
    public function createTransaction(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $data = $request->request->all();
        $constraints = new Assert\Collection([
            'orderId' => [new Assert\NotBlank(), new Assert\Type('string')],
            'transactionId' => [new Assert\NotBlank(), new Assert\Type('string')],
            'paymentMethod' => [new Assert\NotBlank(), new Assert\Type('string')],
        ]);

        $errors = $this->validator->validateFields($data, $constraints);
        if (count($errors) > 0) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
        }
        $ownershipError = $this->assertOrderOwnership($data['orderId'], $context);
        if ($ownershipError) {
            return $ownershipError;
        }

        $order = $this->orderService->getOrderDetailsById($data['orderId'], $context->getContext());
        $expectedAmount = round((float) $order->getAmountTotal(), 2);
        $currency = $order->getCurrency()?->getIsoCode() ?? $context->getCurrency()->getIsoCode();

        if ($this->blueSnapTransactionService->transactionIdAlreadyUsed($data['transactionId'], $context->getContext())) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'Transaction already recorded'), 409);
        }

        $verifiedData = $this->blueSnapClient->fetchTransactionData($data['transactionId'], $context->getSalesChannelId());

        if (!$this->blueSnapClient->isVerifiedTransactionData($verifiedData, $data['transactionId'], $expectedAmount, $currency)) {
            $this->logger->warning('create-transaction rejected: BlueSnap did not confirm the transaction', [
                'orderId' => $data['orderId'],
            ]);

            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'Transaction could not be verified'), 400);
        }

        $this->blueSnapTransactionService->addTransaction(
            $data['orderId'],
            $data['paymentMethod'],
            $data['transactionId'],
            TransactionStatuses::PAID->value,
            $context->getContext(),
            BlueSnapApiClient::extractVerificationCodes($verifiedData)
        );

        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, 'Transaction created!'));
    }

    #[Route(path: '/store-api/handle-payment', name: 'store-api.payment.handle', methods: ['POST'])]
    public function handlePayment(Request $request, SalesChannelContext $context): BlueSnapApiResponse|HandlePaymentMethodRouteResponse
    {
        $data = $request->request->all();

        $ownershipError = $this->assertOrderOwnership($data['orderId'] ?? null, $context);
        if ($ownershipError) {
            return $ownershipError;
        }

        $order = $this->orderService->getOrderDetailsById($data['orderId'], $context->getContext());
        if ($order) {
            $orderTransaction = OrderService::latestTransaction($order);
            $paymentMethod = $orderTransaction?->getPaymentMethod();
            if (!$paymentMethod) {
                return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'Not found'), 404);
            }

            $bluesnapPaymentMethods = new PaymentMethods();
            $handlers = [];
            foreach ($bluesnapPaymentMethods::PAYMENT_METHODS as $method) {
                $method = new $method();
                $handlers[] = $method->getPaymentHandler();
            }
            if (!in_array($paymentMethod->getHandlerIdentifier(), $handlers)) {
                return $this->handlePaymentMethodRoute->load($request, $context);
            }
        }

        $constraints = new Assert\Collection([
            'orderId' => [new Assert\NotBlank(), new Assert\Type('string')],
            'finishUrl' => [new Assert\NotBlank(), new Assert\Type('string')],
            'errorUrl' => [new Assert\NotBlank(), new Assert\Type('string')],
        ]);

        $errors = $this->validator->validateFields($data, $constraints);
        if (count($errors) > 0) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
        }
        $transactionId = $this->orderService->getOrderTransactionIdByOrderId($data['orderId'], $context->getContext());
        $bluesnapTransaction = $this->blueSnapTransactionService->getTransactionByOrderId($data['orderId'], $context->getContext());
        if ($bluesnapTransaction === null || $bluesnapTransaction->getStatus() != 'paid') {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $data['errorUrl']));
        }
        $this->transactionStateHandler->paid($transactionId, $context->getContext());

        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $data['finishUrl']));
    }

    #[Route(path: '/store-api/bluesnap/re-send-payment-link', name: 'store-api.bluesnap.reSendPaymentLink', methods: ['POST'])]
    public function reSendPaymentLink(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $data = $request->request->all();

        $errors = $this->validateResendPaymentLinkInput($data);
        if (count($errors) > 0) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
        }

        $customer = $context->getCustomer();
        $order = $this->orderService->getOrderDetailsById($data['orderId'], $context->getContext());
        $orderCustomerId = $order?->getOrderCustomer()?->getCustomerId();
        if (!$customer || !$order || $orderCustomerId !== $customer->getId()) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'Not found'), 404);
        }

        $this->doResendPaymentLink($data['orderId'], $order, $context->getContext());

        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, 'Payment link sent!'));
    }

    /**
     * Admin-initiated resend. Access is gated by the `order.editor` ACL on the
     * /api/re-send-payment-link route.
     */
    public function adminReSendPaymentLink(Request $request, Context $context): BlueSnapApiResponse
    {
        $data = $request->request->all();

        $errors = $this->validateResendPaymentLinkInput($data);
        if (count($errors) > 0) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
        }

        $order = $this->orderService->getOrderDetailsById($data['orderId'], $context);
        if (!$order) {
            return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'No Order Found!'), 400);
        }

        $this->doResendPaymentLink($data['orderId'], $order, $context);

        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, 'Payment link sent!'));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<int, mixed>
     */
    private function validateResendPaymentLinkInput(array $data): array
    {
        $constraints = new Assert\Collection([
            'orderId' => [new Assert\NotBlank(), new Assert\Type('string')],
        ]);

        return $this->validator->validateFields($data, $constraints);
    }

    private function doResendPaymentLink(string $orderId, OrderEntity $order, Context $context): void
    {
        $paymentLink = $this->paymentLinkService->generatePaymentLink($order, 'payment-link-success', 'payment-link-fail', $context, false, $order->getSalesChannelID());
        $this->paymentLinkService->storePaymentLink($orderId, $paymentLink, $context);
        $this->paymentLinkService->sendEmail($paymentLink, $order, $order->getSalesChannelID(), $context);
    }

    #[Route(path: '/store-api/bluesnap/test-connection', name: 'store-api.bluesnap.testConnection', methods: ['POST'])]
    public function testConnection(Request $request, Context $context): BlueSnapApiResponse
    {


        return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, 'Test connection!'));
    }
}
