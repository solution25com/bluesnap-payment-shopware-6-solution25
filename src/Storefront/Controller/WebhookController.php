<?php

declare(strict_types=1);

namespace BlueSnap\Storefront\Controller;

use BlueSnap\Core\Content\BlueSnap\SalesChannel\BlueSnapRoute;
use BlueSnap\Gateways\ApplePay;
use BlueSnap\Gateways\CreditCard;
use BlueSnap\Gateways\GooglePay;
use BlueSnap\Gateways\HostedCheckout;
use BlueSnap\Gateways\LinkPayment;
use BlueSnap\Library\Constants\TransactionStatuses;
use BlueSnap\Library\WebhookSignature;
use BlueSnap\Service\BlueSnapApiClient;
use BlueSnap\Service\BlueSnapConfig;
use BlueSnap\Service\BlueSnapTransactionService;
use BlueSnap\Service\OrderService;
use BlueSnap\Service\OrderSurchargeService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Psr\Log\LoggerInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class WebhookController
{
    private const THREE_D_NOT_ENABLED = '3DS Not Enabled';

    private const ACCEPTED = 'accepted';

    private const REJECTED = 'rejected';

    private const UNAVAILABLE = 'unavailable';

    private const HANDLERS = [
        LinkPayment::class,
        HostedCheckout::class,
        CreditCard::class,
        ApplePay::class,
        GooglePay::class,
    ];

    private OrderService $orderService;
    private OrderTransactionStateHandler $transactionStateHandler;
    private BlueSnapTransactionService $blueSnapTransactionService;
    private BlueSnapConfig $blueSnapConfig;
    private BlueSnapApiClient $blueSnapApiClient;
    private StateMachineRegistry $stateMachineRegistry;
    private OrderSurchargeService $orderSurchargeService;
    private LoggerInterface $logger;

    public function __construct(
        OrderService $orderService,
        OrderTransactionStateHandler $transactionStateHandler,
        BlueSnapTransactionService $blueSnapTransactionService,
        BlueSnapConfig $blueSnapConfig,
        BlueSnapApiClient $blueSnapApiClient,
        StateMachineRegistry $stateMachineRegistry,
        OrderSurchargeService $orderSurchargeService,
        LoggerInterface $logger
    ) {
        $this->orderService = $orderService;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->blueSnapTransactionService = $blueSnapTransactionService;
        $this->blueSnapConfig = $blueSnapConfig;
        $this->blueSnapApiClient = $blueSnapApiClient;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->orderSurchargeService = $orderSurchargeService;
        $this->logger = $logger;
    }

    #[Route(path: '/webhook', name: 'api.webhook', methods: ['POST'])]
    public function webhook(Request $request, SalesChannelContext $context): JsonResponse
    {

        $rawData = $request->getContent();

        $signatureStatus = $this->signatureStatus($request, $rawData, $context);
        if ($signatureStatus !== self::ACCEPTED) {
            return new JsonResponse(['status' => false], $signatureStatus === self::UNAVAILABLE ? 503 : 403);
        }

        parse_str($rawData, $params);

        $this->logger->info('BlueSnap webhook received', [
            'transactionType' => $params['transactionType'] ?? null,
            'merchantTransactionId' => $params['merchantTransactionId'] ?? null,
            'captureReferenceNumber' => $params['captureReferenceNumber'] ?? null,
            '3DStatus' => $params['3DStatus'] ?? null,
        ]);

        $transactionType = $params['transactionType'] ?? '';
        $captureReferenceNumber = (string) ($params['captureReferenceNumber'] ?? '');

        if ($transactionType !== 'CHARGE' && $transactionType !== 'REFUND') {
            return new JsonResponse(['status' => false]);
        }

        $enabledThreeD = $this->blueSnapConfig->getConfig('threeDS', $context->getSalesChannelId());
        $threeD = (string) ($params['3DStatus'] ?? '');
        if ($enabledThreeD && $threeD !== '' && $threeD !== self::THREE_D_NOT_ENABLED && !BlueSnapRoute::isAcceptable3DSecureResult($threeD)) {
            $this->logger->warning('BlueSnap webhook rejected on 3-D Secure status', [
                'reference' => $this->chargeReference($params),
                '3DStatus' => $threeD,
            ]);

            return new JsonResponse(['status' => false]);
        }

        $order = $this->resolveOrder($params, $context);
        if (!$order) {
            return new JsonResponse(['status' => false]);
        }

        $transaction = $this->selectOrderTransaction($order, $this->chargeReference($params), $context);
        if (!$transaction) {
            return new JsonResponse(['status' => false]);
        }

        $handlerIdentifier = $transaction->getPaymentMethod()->getHandlerIdentifier();

        if (!in_array($handlerIdentifier, self::HANDLERS, true)) {
            return new JsonResponse(['status' => false]);
        }

        if ($transactionType === 'REFUND') {
            return $this->handleRefundNotification($order, $transaction, $params, $context);
        }

        $chargeStatus = $this->chargeStatus($order, $params, $context);
        if ($chargeStatus !== self::ACCEPTED) {
            return new JsonResponse(['status' => false], $chargeStatus === self::UNAVAILABLE ? 503 : 200);
        }

        if ($handlerIdentifier === LinkPayment::class || $handlerIdentifier === HostedCheckout::class) {
            try {
                $this->applySurchargeFromIpn($order, $params, $context, $transaction->getId());
            } catch (\Throwable $e) {
                $this->logger->error('Surcharge application failed: ' . $e->getMessage());
            }
        }

        $applied = $this->markTransactionPaid($transaction, $order->getId(), $captureReferenceNumber, $context->getContext());

        return new JsonResponse(['status' => $applied]);
    }

    private function handleRefundNotification($order, $transaction, array $params, SalesChannelContext $context): JsonResponse
    {
        $reference = $this->chargeReference($params);
        if ($reference === '') {
            return new JsonResponse(['status' => false]);
        }

        $data = $this->blueSnapApiClient->fetchTransactionData($reference, $context->getSalesChannelId());
        if ($data === null) {
            $this->logger->error('BlueSnap refund webhook could not be verified: the BlueSnap API did not answer', [
                'orderId' => $order->getId(),
                'reference' => $reference,
            ]);

            return new JsonResponse(['status' => false], 503);
        }

        $currency = strtoupper($order->getCurrency()?->getIsoCode() ?? $context->getCurrency()->getIsoCode());
        $refunded = 0.0;
        foreach ($data['refunds']['refund'] ?? [] as $refund) {
            if (strtoupper((string) ($refund['currency'] ?? $currency)) === $currency) {
                $refunded += (float) ($refund['amount'] ?? 0);
            }
        }

        if ($refunded <= 0.0) {
            $this->logger->warning('BlueSnap refund webhook found no refund on the transaction', [
                'orderId' => $order->getId(),
                'reference' => $reference,
            ]);

            return new JsonResponse(['status' => false]);
        }

        $orderTotal = round((float) $order->getAmountTotal(), 2);
        $isFull = round($refunded, 2) + 0.01 >= $orderTotal;
        $action = $isFull ? StateMachineTransitionActions::ACTION_REFUND : StateMachineTransitionActions::ACTION_REFUND_PARTIALLY;

        if (!$this->canTransition($transaction->getId(), $action, $context->getContext())) {
            $this->logger->warning('BlueSnap refund cannot be applied to this order transaction', [
                'orderId' => $order->getId(),
                'orderTransactionId' => $transaction->getId(),
                'action' => $action,
                'refunded' => round($refunded, 2),
                'orderTotal' => $orderTotal,
            ]);

            return new JsonResponse(['status' => false]);
        }

        $this->blueSnapTransactionService->updateTransactionStatus(
            $order->getId(),
            TransactionStatuses::REFUND->value,
            $context->getContext(),
            '',
            $transaction->getId()
        );

        if ($isFull) {
            $this->transactionStateHandler->refund($transaction->getId(), $context->getContext());
        } else {
            $this->transactionStateHandler->refundPartially($transaction->getId(), $context->getContext());
        }

        $this->logger->info('BlueSnap refund applied to the order', [
            'orderId' => $order->getId(),
            'reference' => $reference,
            'refunded' => round($refunded, 2),
            'orderTotal' => $orderTotal,
            'full' => $isFull,
        ]);

        return new JsonResponse(['status' => true]);
    }

    private function selectOrderTransaction($order, string $reference, SalesChannelContext $context)
    {
        $transactions = $order->getTransactions();
        if ($transactions === null || $transactions->count() === 0) {
            return null;
        }

        $recordedId = $reference !== ''
            ? $this->blueSnapTransactionService->findByTransactionId($reference, $context->getContext())?->getOrderTransactionId()
            : null;

        if ($recordedId !== null && $transactions->get($recordedId) !== null) {
            return $transactions->get($recordedId);
        }

        return OrderService::latestTransaction($order);
    }

    private function canTransition(string $orderTransactionId, string $action, Context $context): bool
    {
        try {
            $transitions = $this->stateMachineRegistry->getAvailableTransitions(
                OrderTransactionDefinition::ENTITY_NAME,
                $orderTransactionId,
                'stateId',
                $context
            );
        } catch (\Throwable $e) {
            $this->logger->warning('BlueSnap webhook could not read the available order transitions', [
                'orderTransactionId' => $orderTransactionId,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        foreach ($transitions as $transition) {
            if ($transition->getActionName() === $action) {
                return true;
            }
        }

        return false;
    }

    private function chargeReference(array $params): string
    {
        foreach (['referenceNumber', 'captureReferenceNumber', 'invoiceId'] as $key) {
            $value = trim((string) ($params[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Only a payment link carries the order id as merchantTransactionId. A charge captured in the
     * BlueSnap portal has none, so the order is found through the reference the plugin already
     * recorded for that transaction.
     */
    private function resolveOrder(array $params, SalesChannelContext $context)
    {
        $merchantTransactionId = trim((string) ($params['merchantTransactionId'] ?? ''));

        if ($merchantTransactionId !== '' && Uuid::isValid($merchantTransactionId)) {
            return $this->orderService->getOrderDetailsById($merchantTransactionId, $context->getContext());
        }

        $reference = $this->chargeReference($params);
        if ($reference === '') {
            return null;
        }

        $record = $this->blueSnapTransactionService->findByTransactionId($reference, $context->getContext());
        $orderId = $record?->getOrderId();

        if (!$orderId) {
            $this->logger->info('BlueSnap webhook reference does not belong to this shop', [
                'reference' => $reference,
            ]);

            return null;
        }

        return $this->orderService->getOrderDetailsById($orderId, $context->getContext());
    }

    private function signatureStatus(Request $request, string $rawBody, SalesChannelContext $context): string
    {
        $secret = trim((string) ($this->blueSnapConfig->getConfig('webhookSecret', $context->getSalesChannelId()) ?? ''));
        if ($secret === '') {
            $this->logger->error(
                'BlueSnap webhook rejected: no Security Header key configured. Generate one under '
                . 'Add Security Header in the BlueSnap webhook settings and store it in the plugin configuration.'
            );

            return self::UNAVAILABLE;
        }

        $signature = (string) $request->headers->get('Bls-Signature', '');
        $timestamp = (string) $request->headers->get('Bls-Ipn-Timestamp', '');

        if ($signature === '' || $timestamp === '') {
            $this->logger->warning('BlueSnap webhook rejected: signature headers missing');

            return self::REJECTED;
        }

        if (!WebhookSignature::matches($signature, $timestamp, $rawBody, $secret)) {
            $this->logger->warning('BlueSnap webhook rejected: signature mismatch');

            return self::REJECTED;
        }

        if (!WebhookSignature::isFresh($timestamp)) {
            $this->logger->warning('BlueSnap webhook rejected: timestamp outside the accepted window', [
                'timestamp' => $timestamp,
            ]);

            return self::REJECTED;
        }

        return self::ACCEPTED;
    }


    /**
     * A webhook body proves nothing on its own, so the referenced charge is confirmed with BlueSnap
     * before any order is marked paid: the reference must exist, have succeeded, match this order's
     * amount and currency, and not already be recorded against another order.
     */
    private function chargeStatus($order, array $params, SalesChannelContext $context): string
    {
        $reference = $this->chargeReference($params);
        if ($reference === '') {
            $this->logger->warning('BlueSnap webhook carried no transaction reference to verify', [
                'orderId' => $order->getId(),
            ]);

            return self::REJECTED;
        }

        $record = $this->blueSnapTransactionService->findByTransactionId($reference, $context->getContext());
        if ($record !== null && $record->getOrderId() !== $order->getId()) {
            $this->logger->warning('BlueSnap webhook reference is already recorded against another order', [
                'orderId' => $order->getId(),
                'recordedOrderId' => $record->getOrderId(),
            ]);

            return self::REJECTED;
        }

        if ($record !== null && strtolower($record->getStatus()) === TransactionStatuses::PAID->value) {
            $this->logger->info('BlueSnap webhook repeats a charge already settled for this order', [
                'orderId' => $order->getId(),
                'reference' => $reference,
            ]);

            return self::REJECTED;
        }

        $orderTotal = round((float) $order->getAmountTotal(), 2);
        $charged = round((float) str_replace(',', '', (string) ($params['invoiceChargeAmount'] ?? '0')), 2);

        if ($charged < $orderTotal) {
            $this->logger->warning('BlueSnap webhook claims a charge smaller than the order total', [
                'orderId' => $order->getId(),
                'orderTotal' => $orderTotal,
                'claimedCharge' => $charged,
            ]);

            return self::REJECTED;
        }

        $currency = $order->getCurrency()?->getIsoCode() ?? $context->getCurrency()->getIsoCode();

        $data = $this->blueSnapApiClient->fetchTransactionData($reference, $context->getSalesChannelId());

        if ($data === null) {
            $this->logger->error('BlueSnap webhook could not be verified: the BlueSnap API did not answer', [
                'orderId' => $order->getId(),
                'reference' => $reference,
            ]);

            return self::UNAVAILABLE;
        }

        $expectedMerchantTransactionId = trim((string) ($params['merchantTransactionId'] ?? '')) !== ''
            ? $order->getId()
            : null;

        if (!$this->blueSnapApiClient->isVerifiedTransactionData($data, $reference, $charged, $currency, $expectedMerchantTransactionId)) {
            $this->logger->warning('BlueSnap webhook did not match the charge held by BlueSnap', [
                'orderId' => $order->getId(),
                'expectedAmount' => $charged,
                'expectedCurrency' => $currency,
            ]);

            return self::REJECTED;
        }

        return self::ACCEPTED;
    }

    private function markTransactionPaid($transaction, string $orderId, string $captureReferenceNumber, Context $context): bool
    {
        if (!$this->canTransition($transaction->getId(), StateMachineTransitionActions::ACTION_PAID, $context)) {
            $this->logger->warning('BlueSnap charge cannot be applied to this order transaction', [
                'orderId' => $orderId,
                'orderTransactionId' => $transaction->getId(),
            ]);

            return false;
        }

        $this->blueSnapTransactionService->updateTransactionStatus($orderId, TransactionStatuses::PAID->value, $context, $captureReferenceNumber, $transaction->getId());

        $this->transactionStateHandler->paid($transaction->getId(), $context);

        return true;
    }

    /**
     * BlueSnap adds its own surcharge on the hosted page, so the order has to be brought up to the
     * amount that was actually charged. The surcharge service owns that line item, including the
     * identifier that survives a recalculation and the transaction amount that follows it.
     */
    private function applySurchargeFromIpn($order, array $params, SalesChannelContext $context, string $orderTransactionId): void
    {
        $charged = round((float) str_replace(',', '', (string) ($params['invoiceChargeAmount'] ?? '0')), 2);
        if ($charged <= 0.0) {
            return;
        }

        $existing = $this->orderSurchargeService->getSurchargeOnOrder($order);
        $base = round((float) $order->getAmountTotal() - $existing, 2);
        $surcharge = round($charged - $base, 2);

        if (abs($surcharge - $existing) < 0.01) {
            return;
        }

        if ($surcharge < 0.0) {
            $this->logger->warning('BlueSnap charged less than the order is worth', [
                'orderId' => $order->getId(),
                'charged' => $charged,
                'orderBase' => $base,
            ]);

            return;
        }

        $this->orderSurchargeService->applyToOrder($order, '', $surcharge, $context->getContext(), $orderTransactionId);

        $this->logger->info('BlueSnap surcharge from the webhook applied to the order', [
            'orderId' => $order->getId(),
            'surcharge' => $surcharge,
            'charged' => $charged,
        ]);
    }
}
