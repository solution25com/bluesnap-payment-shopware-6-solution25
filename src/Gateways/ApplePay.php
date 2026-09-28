<?php

namespace BlueSnap\Gateways;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use BlueSnap\Library\Constants\TransactionStatuses;
use BlueSnap\Service\BlueSnapApiClient;
use BlueSnap\Service\BlueSnapConfig;
use BlueSnap\Service\BlueSnapTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use BlueSnap\Service\OrderService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class ApplePay extends AbstractPaymentHandler
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private BlueSnapTransactionService $blueSnapTransactionService;

    private BlueSnapConfig $blueSnapConfig;

    private OrderService $orderService;

    private BlueSnapApiClient $blueSnapApiClient;
    private LoggerInterface $logger;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        BlueSnapTransactionService $blueSnapTransactionService,
        BlueSnapConfig $blueSnapConfig,
        OrderService $orderService,
        BlueSnapApiClient $blueSnapApiClient,
        LoggerInterface $logger
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->blueSnapTransactionService = $blueSnapTransactionService;
        $this->blueSnapConfig = $blueSnapConfig;
        $this->orderService = $orderService;
        $this->blueSnapApiClient = $blueSnapApiClient;
        $this->logger = $logger;
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        // This payment handler does not support recurring payments nor refunds
        return false;
    }

    /**
     * @inheritDoc
     */
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

        if ($flow == 'payment_order') {
            $this->paymentFirstFlow($request, $transaction, $orderTransaction, $transactionMethodName, $transactionStatus, $salesChannelId, $context);
        } else {
            $this->orderFirstFlow($request, $transaction, $orderTransaction, $authorizeOption, $transactionMethodName, $transactionStatus, $salesChannelId, $context);
        }

        return null;
    }

    private function orderFirstFlow(Request $request, PaymentTransactionStruct $transaction, OrderTransactionEntity $orderTransaction, string $cardTransactionType, string $handlerMethodName, string $transactionStatus, string $salesChannelId, Context $context): void
    {
        $order = $orderTransaction->getOrder();
        $currency = $order->getCurrency();

        if (!$request->request->get('paymentData')) {
            $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
            $this->logger->error('Missing paymentData');
            throw new \RuntimeException('Missing paymentData');
        }
        $paymentData = json_decode($request->request->get('paymentData'), true);

        $body = [
            'amount' => $orderTransaction->getAmount()->getTotalPrice(),
            "softDescriptor" => "Apple Pay",
            "currency" => $currency->getIsoCode(),
            "cardTransactionType" => $cardTransactionType,
            "wallet" => [
                "walletType" => "APPLE_PAY",
                "encodedPaymentToken" => $paymentData['appleToken'],
            ],
            "cardHolderInfo" => [
                "email" => $paymentData['email']
            ]];


        if ($this->blueSnapConfig->level23DataConfigs($order->getSalesChannelId(), $order->getOrderCustomer()->getCustomer()->getGroupId())) {
            $formatedCartValue = $this->orderService->extractLVL2And3DataFromOrder($order);
            $level3Data = $this->orderService->buildLevel3Data($formatedCartValue, $context);
            if (!empty($level3Data)) {
                $body['level3Data'] = $level3Data;
            }
        }

        if (!empty($paymentData['surchargeToken'])) {
            $body['surchargeInfo'] = [
                'surchargeToken' => $paymentData['surchargeToken'],
            ];
        }

        $response = $this->blueSnapApiClient->capture($body, $salesChannelId, $transaction->getOrderTransactionId());
        if (isset($response['error'])) {
            $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
            throw new \RuntimeException($response['error']);
        }
        $responseData = json_decode($response, true);
        $this->blueSnapTransactionService->addTransaction($order->getId(), $orderTransaction->getPaymentMethod()->getName(), $responseData['transactionId'], $transactionStatus, $context, BlueSnapApiClient::extractVerificationCodes($responseData), $transaction->getOrderTransactionId());
        $this->transactionStateHandler->{$handlerMethodName}($transaction->getOrderTransactionId(), $context);
    }

    private function paymentFirstFlow(Request $request, PaymentTransactionStruct $transaction, OrderTransactionEntity $orderTransaction, string $handlerMethodName, string $transactionStatus, string $salesChannelId, Context $context): void
    {
        $bluesnapTransactionId = (string) $request->request->get('bluesnap_transaction_id');
        $order = $orderTransaction->getOrder();
        $orderId = $order->getId();
        $expectedAmount = $orderTransaction->getAmount()->getTotalPrice();
        $expectedCurrency = $order->getCurrency()->getIsoCode();

        if (
            $bluesnapTransactionId === ''
            || $this->blueSnapTransactionService->transactionIdAlreadyUsed($bluesnapTransactionId, $context)
            || !$this->blueSnapApiClient->verifyTransaction($bluesnapTransactionId, $expectedAmount, $expectedCurrency, $salesChannelId)
        ) {
            $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
            $this->logger->error('BlueSnap transaction reference could not be verified', ['orderId' => $orderId]);
            throw new \RuntimeException('BlueSnap transaction verification failed');
        }

        $this->blueSnapTransactionService->addTransaction($orderId, $orderTransaction->getPaymentMethod()->getName(), $bluesnapTransactionId, $transactionStatus, $context, null, $transaction->getOrderTransactionId());
        $this->transactionStateHandler->{$handlerMethodName}($transaction->getOrderTransactionId(), $context);
    }
}
