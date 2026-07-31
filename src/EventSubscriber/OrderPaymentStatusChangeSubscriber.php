<?php

namespace BlueSnap\EventSubscriber;

use BlueSnap\Core\Content\Transaction\BluesnapTransactionEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Shopware\Core\System\StateMachine\StateMachineException;
use BlueSnap\Library\Constants\TransactionStatuses;
use BlueSnap\Service\BlueSnapApiClient;
use BlueSnap\Service\BlueSnapTransactionService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderPaymentStatusChangeSubscriber implements EventSubscriberInterface
{
    private BlueSnapTransactionService $transactionService;
    private BlueSnapApiClient $apiService;
    private EntityRepository $orderRepository;
    private EntityRepository $orderTransactionRepository;
    private LoggerInterface $logger;

    public function __construct(
        BlueSnapTransactionService $transactionService,
        BlueSnapApiClient $apiService,
        EntityRepository $orderRepository,
        EntityRepository $orderTransactionRepository,
        LoggerInterface $logger
    ) {
        $this->transactionService = $transactionService;
        $this->apiService = $apiService;
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onStateMachineTransition',
        ];
    }

    public function onStateMachineTransition(StateMachineTransitionEvent $event): void
    {


        $nextState = strtolower($event->getToPlace()->getTechnicalName());
        $context = $event->getContext();

        $transactionId = $event->getEntityId();
        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('paymentMethod');

        /** @var OrderTransactionEntity|null $orderTransaction */
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if (!$orderTransaction) {
            $this->logger->warning("Order transaction not found for ID: $transactionId");
            return;
        }

        $paymentMethod = $orderTransaction->getPaymentMethod();
        if (!$paymentMethod) {
            $this->logger->warning("Payment method not found for transaction ID: $transactionId");
            return;
        }

        $handlerIdentifier = $paymentMethod->getHandlerIdentifier();

        if (!str_starts_with($handlerIdentifier, 'BlueSnap\\Gateways\\')) {
            return;
        }

        $orderId = $orderTransaction->getOrderId();
        /** @var BluesnapTransactionEntity|null $blueSnapTransaction */
        $blueSnapTransaction = $this->transactionService->getTransactionByOrderId($orderId, $context);
        if (!$blueSnapTransaction) {
            $this->logger->warning("BlueSnap transaction not found for order ID: $orderId");
            return;
        }

        $currentStatus = strtolower($blueSnapTransaction->getStatus());

        if ($nextState === 'paid' && $currentStatus === 'authorized') {
            try {
                $this->handleCapture($orderId, $context, $blueSnapTransaction->getTransactionId());
            } catch (\Exception $e) {
                $this->logger->error('BlueSnap capture failed: ' . $e->getMessage());
                throw new StateMachineException(400, 'BLUESNAP_CAPTURE_FAILED', 'Failed to capture authorized payment.');
            }
            return;
        }

        if ($nextState === 'cancelled' && $currentStatus === 'authorized') {
            try {
                $this->handleVoid($orderId, $context, $blueSnapTransaction->getTransactionId());
            } catch (\Exception $e) {
                $this->logger->error('BlueSnap auth reversal failed: ' . $e->getMessage());
                throw new StateMachineException(400, 'BLUESNAP_AUTH_REVERSAL_FAILED', 'Failed to reverse authorization.');
            }
        }

        if ($nextState === 'refunded' && $currentStatus === 'paid') {
            try {
                $this->handleRefund($orderId, $context, $blueSnapTransaction->getTransactionId());
            } catch (\Exception $e) {
                $this->logger->error('BlueSnap refund failed: ' . $e->getMessage());
                throw new StateMachineException(400, 'BLUESNAP_REFUND_FAILED', 'Failed to refund payment.');
            }
        }
    }

    private function handleCapture(string $orderId, Context $context, string $blueSnapTransactionId): void
    {
        $criteria = new Criteria([$orderId]);
        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $context)->first();

        if (!$order) {
            throw new \Exception("Order not found for ID $orderId");
        }

        $amount = $order->getAmountTotal();

        $body = [
            "amount" => round($amount, 2),
            "cardTransactionType" => "CAPTURE",
            "transactionId" => $blueSnapTransactionId,
        ];

        $surchargeToken = $this->getSurchargeTokenFromOrder($order);
        if ($surchargeToken !== null) {
            $body['surchargeInfo'] = [
                'surchargeToken' => $surchargeToken,
            ];
        }

        $response = $this->apiService->captureTransactionOrVoid($body, $order->getSalesChannelId());

        if (isset($response['error'])) {
            $this->logger->error('BlueSnap capture API error', ['response' => $response]);
            throw new \Exception($response['message'] ?? 'Unknown capture error');
        }

        $this->transactionService->updateTransactionStatus($orderId, TransactionStatuses::PAID->value, $context);

        $this->logger->info("BlueSnap capture successful for order $orderId");
    }

    private function handleVoid(string $orderId, Context $context, string $blueSnapTransactionId): void
    {
        $criteria = new Criteria([$orderId]);
        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $context)->first();

        if (!$order) {
            throw new \Exception("Order not found for ID $orderId");
        }

        $amount = $order->getAmountTotal();

        $body = [
            "amount" => round($amount, 2),
            "cardTransactionType" => "AUTH_REVERSAL",
            "transactionId" => $blueSnapTransactionId,
        ];

        $surchargeToken = $this->getSurchargeTokenFromOrder($order);
        if ($surchargeToken !== null) {
            $body['surchargeInfo'] = [
                'surchargeToken' => $surchargeToken,
            ];
        }

        $response = $this->apiService->captureTransactionOrVoid($body, $order->getSalesChannelId());

        if (isset($response['error'])) {
            $this->logger->error('BlueSnap capture API error', ['response' => $response]);
            throw new \Exception($response['message'] ?? 'Unknown capture error');
        }

        $this->transactionService->updateTransactionStatus($orderId, TransactionStatuses::CANCELLED->value, $context);

        $this->logger->info("BlueSnap capture successful for order $orderId");
    }

    private function handleRefund(string $orderId, Context $context, string $blueSnapTransactionId): void
    {
        $criteria = new Criteria([$orderId]);
        $order = $this->orderRepository->search($criteria, $context)->first();

        if (!$order) {
            throw new \Exception("Order not found for ID $orderId");
        }

        $body = [
            "transactionId" => $blueSnapTransactionId,
        ];

        $response = $this->apiService->refund($blueSnapTransactionId, $body);

        if (isset($response['error'])) {
            $this->logger->error('BlueSnap capture API error', ['response' => $response]);
            throw new \Exception($response['message'] ?? 'Unknown capture error');
        }

        $this->transactionService->updateTransactionStatus($orderId, TransactionStatuses::REFUND->value, $context);

        $this->logger->info("BlueSnap capture successful for order $orderId");
    }

    private function getSurchargeTokenFromOrder(OrderEntity $order): ?string
    {
        $customFields = $order->getCustomFields();
        if (!is_array($customFields)) {
            return null;
        }

        $surcharge = $customFields['bluesnap_surcharge'] ?? null;
        if (!is_array($surcharge)) {
            return null;
        }

        $token = $surcharge['token'] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }

        return $token;
    }
}
