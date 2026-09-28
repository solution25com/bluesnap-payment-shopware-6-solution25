<?php

namespace BlueSnap\Gateways;

use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use BlueSnap\Core\Checkout\Cart\BlueSnapSurchargeCartProcessor;
use BlueSnap\Core\Content\BlueSnap\SalesChannel\BlueSnapRoute;
use BlueSnap\Library\CardHolderInfo;
use BlueSnap\Library\Constants\TransactionStatuses;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use BlueSnap\Service\BlueSnapApiClient;
use BlueSnap\Service\BlueSnapConfig;
use BlueSnap\Service\BlueSnapTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use BlueSnap\Service\OrderService;
use BlueSnap\Service\OrderSurchargeService;
use BlueSnap\Service\SavedCardService;
use BlueSnap\Service\VaultedShopperService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class CreditCard extends AbstractPaymentHandler
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private BlueSnapTransactionService $blueSnapTransactionService;
    private BlueSnapApiClient $blueSnapApiClient;
    private VaultedShopperService $vaultedShopperService;
    private BlueSnapConfig $blueSnapConfig;
    private OrderService $orderService;
    private SavedCardService $savedCardService;
    private OrderSurchargeService $orderSurchargeService;
    /** @var array<string, mixed> */
    private array $amountTrace = [];
    private LoggerInterface $logger;


    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        BlueSnapTransactionService $blueSnapTransactionService,
        BlueSnapApiClient $blueSnapApiClient,
        VaultedShopperService $vaultedShopperService,
        BlueSnapConfig $blueSnapConfig,
        OrderService $orderService,
        SavedCardService $savedCardService,
        OrderSurchargeService $orderSurchargeService,
        LoggerInterface $logger
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->blueSnapTransactionService = $blueSnapTransactionService;
        $this->blueSnapApiClient = $blueSnapApiClient;
        $this->vaultedShopperService = $vaultedShopperService;
        $this->blueSnapConfig = $blueSnapConfig;
        $this->orderService = $orderService;
        $this->savedCardService = $savedCardService;
        $this->orderSurchargeService = $orderSurchargeService;
        $this->logger = $logger;
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        // This payment handler does not support recurring payments nor refunds
        return false;
    }

    private function orderFirstFlow(Request $request, PaymentTransactionStruct $transaction, OrderTransactionEntity $orderTransaction, string $cardTransactionType, string $handlerMethodName, string $transactionStatus, string $salesChannelId, Context $context): void
    {
        $order = $orderTransaction->getOrder();
        $customer = $order->getOrderCustomer()->getCustomer();

        $isGuestCustomer = $customer->getGuest();
        $currency = $order->getCurrency();
        $billingAddress = $order->getBillingAddress();

        if (!$request->request->get('paymentData')) {
            $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
            throw new \RuntimeException('Missing paymentData');
        }
        $paymentData = json_decode($request->request->get('paymentData'), true);
        $orderTransaction = $this->applySurchargeToOrder($order, $orderTransaction, $paymentData, $context);
        $order = $orderTransaction->getOrder();

        $amount = $orderTransaction->getAmount()->getTotalPrice();
        $this->amountTrace = [
            'amount' => $amount,
            'surchargeOnOrder' => $this->orderSurchargeService->getSurchargeOnOrder($order),
        ];

        $saveCard = !empty($paymentData['saveCard']) && !$isGuestCustomer;
        $existingVaultedShopperId = $saveCard && empty($paymentData['vaultedId'])
            ? $this->vaultedShopperService->getVaultedShopperIdByCustomerId($context, $customer->getId())
            : null;

        if (isset($paymentData['vaultedId'])) {
            $body = [
                "amount" => $amount,
                "vaultedShopperId" => $paymentData['vaultedId'],
                "softDescriptor" => "Card Capture",
                "currency" => $currency->getIsoCode(),
                "cardTransactionType" => $cardTransactionType,
            ];
            $cardKey = (string) ($paymentData['cardKey'] ?? '');
            if ($cardKey !== '') {
                $cardSelector = $this->savedCardService->getCardSelector($customer, $cardKey, $salesChannelId, $context);

                if ($cardSelector === null) {
                    $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
                    throw new \RuntimeException('Selected saved card not found');
                }

                $body['creditCard'] = $cardSelector;
            }
        } else {
            $body = [
                "amount" => $amount,
                "softDescriptor" => "Card Capture",
                "currency" => $currency->getIsoCode(),
                "cardHolderInfo" => CardHolderInfo::build(
                    $billingAddress,
                    (string) $paymentData['firstName'],
                    (string) $paymentData['lastName'],
                    $customer->getEmail()
                ),
                "pfToken" => $paymentData['pfToken'],
                "cardTransactionType" => $cardTransactionType,
                "transactionInitiator" => "SHOPPER"
            ];

            if ($existingVaultedShopperId !== null) {
                $body['vaultedShopperId'] = $existingVaultedShopperId;
            }
        }

        $is3DSEnabled = $this->blueSnapConfig->getConfig('threeDS', $salesChannelId);
        /* @phpstan-ignore-next-line */
        if ($is3DSEnabled) {
            if (!BlueSnapRoute::isAcceptable3DSecureResult((string) ($paymentData['authResult'] ?? ''))) {
                $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
                $this->logger->error('3D Secure authentication did not succeed', [
                    'orderId' => $order->getId(),
                    'authResult' => $paymentData['authResult'] ?? null,
                ]);

                throw new \RuntimeException('3D Secure authentication required');
            }
        }

        if ($is3DSEnabled && !empty($paymentData['threeDSecureReferenceId'])) {
            $body['threeDSecure'] = [
                "authResult" => $paymentData['authResult'],
                "threeDSecureReferenceId" => $paymentData['threeDSecureReferenceId']
            ];
        }

        if (!empty($paymentData['surchargeToken'])) {
            $body['surchargeInfo'] = [
                'surchargeToken' => $paymentData['surchargeToken'],
            ];
        }

        if ($this->blueSnapConfig->level23DataConfigs($order->getSalesChannelId(), $order->getOrderCustomer()->getCustomer()->getGroupId())) {
            $formatedCartValue = $this->orderService->extractLVL2And3DataFromOrder($order);
            $level3Data = $this->orderService->buildLevel3Data($formatedCartValue, $context);
            if (!empty($level3Data)) {
                $body['level3Data'] = $level3Data;
            }
        }

        $response = $this->blueSnapApiClient->capture($body, $salesChannelId, $transaction->getOrderTransactionId());

        if (isset($response['error'])) {
            $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
            $this->logger->error('BlueSnap capture API error', [
                'response' => $response,
                'request' => [
                    'amount' => $body['amount'],
                    'currency' => $body['currency'],
                    'cardTransactionType' => $body['cardTransactionType'],
                    'hasSurchargeToken' => isset($body['surchargeInfo']),
                    'hasVaultedShopper' => isset($body['vaultedShopperId']),
                    'hasCardSelector' => isset($body['creditCard']),
                    'hasThreeDSecure' => isset($body['threeDSecure']),
                    'amountTrace' => $this->amountTrace,
                ],
            ]);
            throw new \RuntimeException($response['error']);
        }

        $responseData = json_decode($response, true);
        if ($responseData && $responseData['vaultedShopperId']) {
            $vaultedShopperId = $responseData['vaultedShopperId'];

            if ($saveCard) {
                $this->vaultedShopperService->store($vaultedShopperId, $paymentData['cardType'] ?? null, $customer->getId(), $context);
            }
        }

        $this->blueSnapTransactionService->addTransaction($order->getId(), $orderTransaction->getPaymentMethod()->getName(), $responseData['transactionId'], $transactionStatus, $context, BlueSnapApiClient::extractVerificationCodes($responseData), $transaction->getOrderTransactionId());
        $this->transactionStateHandler->{$handlerMethodName}($transaction->getOrderTransactionId(), $context);
    }

    private function applySurchargeToOrder(
        \Shopware\Core\Checkout\Order\OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        array $paymentData,
        Context $context
    ): OrderTransactionEntity {
        $token = (string) ($paymentData['surchargeToken'] ?? '');
        $amount = (float) ($paymentData['surchargeAmount'] ?? 0);

        if ($token === '') {
            return $orderTransaction;
        }

        $this->orderSurchargeService->applyToOrder($order, $token, $amount, $context, $orderTransaction->getId());

        $reloaded = $this->orderService->getOrderTransactionsById($orderTransaction->getId(), $context);

        return $reloaded ?? $orderTransaction;
    }

    private function paymentFirstFlow(Request $request, PaymentTransactionStruct $transaction, OrderTransactionEntity $orderTransaction, string $handlerMethodName, string $transactionStatus, string $salesChannelId, Context $context): void
    {
        $bluesnapTransactionId = (string) $request->request->get('bluesnap_transaction_id');
        $order = $orderTransaction->getOrder();
        $orderId = $order->getId();
        $expectedAmount = $orderTransaction->getAmount()->getTotalPrice();
        $expectedCurrency = $order->getCurrency()->getIsoCode();

        $verifiedData = $bluesnapTransactionId !== ''
            ? $this->blueSnapApiClient->fetchTransactionData($bluesnapTransactionId, $salesChannelId)
            : null;

        if (
            $bluesnapTransactionId === ''
            || $this->blueSnapTransactionService->transactionIdAlreadyUsed($bluesnapTransactionId, $context)
            || !$this->blueSnapApiClient->isVerifiedTransactionData($verifiedData, $bluesnapTransactionId, $expectedAmount, $expectedCurrency)
        ) {
            $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
            $this->logger->error('BlueSnap transaction reference could not be verified', [
                'orderId' => $orderId,
                'orderTransactionId' => $transaction->getOrderTransactionId(),
                'hasTransactionId' => $bluesnapTransactionId !== '',
                'expectedAmount' => $expectedAmount,
                'expectedCurrency' => $expectedCurrency,
            ]);

            throw new \RuntimeException('BlueSnap transaction verification failed');
        }

        $this->blueSnapTransactionService->addTransaction(
            $orderId,
            $orderTransaction->getPaymentMethod()->getName(),
            $bluesnapTransactionId,
            $transactionStatus,
            $context,
            BlueSnapApiClient::extractVerificationCodes($verifiedData),
            $transaction->getOrderTransactionId()
        );
        $this->transactionStateHandler->{$handlerMethodName}($transaction->getOrderTransactionId(), $context);
    }

    public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
    {
        $salesChannelId = $request->attributes->get('sw-sales-channel-id');
        $flow = $this->blueSnapConfig->getConfig('flow', $salesChannelId);

        $authorizeOption = $this->blueSnapConfig->getCardTransactionType($salesChannelId);

        $transactionStatus = $authorizeOption == 'AUTH_ONLY' ? TransactionStatuses::AUTHORIZED->value : TransactionStatuses::PAID->value;
        $transactionMethodName = $authorizeOption == 'AUTH_ONLY' ? 'authorize' : 'paid';

        $orderTransaction = $this->orderService->getOrderTransactionsById($transaction->getOrderTransactionId(), $context);
        if (!$orderTransaction) {
            $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
            throw new \RuntimeException('OrderTransaction not found for ID ' . $transaction->getOrderTransactionId());
        }

        $hasPaymentData = (bool) $request->request->get('paymentData');

        if ($flow == 'payment_order' && !$hasPaymentData) {
            $this->paymentFirstFlow($request, $transaction, $orderTransaction, $transactionMethodName, $transactionStatus, $salesChannelId, $context);
        } else {
            $this->orderFirstFlow($request, $transaction, $orderTransaction, $authorizeOption, $transactionMethodName, $transactionStatus, $salesChannelId, $context);
        }
        return null;
    }
}
