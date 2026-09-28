<?php

namespace BlueSnap\EventSubscriber;

use BlueSnap\Core\Checkout\Cart\BlueSnapSurchargeContext;
use BlueSnap\Gateways\LinkPayment;
use BlueSnap\Library\Constants\TransactionStatuses;
use BlueSnap\Service\BlueSnapTransactionService;
use BlueSnap\Service\OrderService;
use BlueSnap\Service\PaymentLinkService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderPaymentLinkSubscriber implements EventSubscriberInterface
{
    private OrderService $orderService;
    private PaymentLinkService $paymentLinkService;
    private BlueSnapTransactionService $blueSnapTransactionService;
    private BlueSnapSurchargeContext $surchargeContext;
    private EventDispatcherInterface $dispatcher;
    private LoggerInterface $logger;

    public function __construct(
        OrderService $orderService,
        PaymentLinkService $paymentLinkService,
        BlueSnapTransactionService $blueSnapTransactionService,
        BlueSnapSurchargeContext $surchargeContext,
        EventDispatcherInterface $dispatcher,
        LoggerInterface $logger
    ) {
        $this->orderService = $orderService;
        $this->paymentLinkService = $paymentLinkService;
        $this->blueSnapTransactionService = $blueSnapTransactionService;
        $this->surchargeContext = $surchargeContext;
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents()
    {
        return [
            OrderEvents::ORDER_WRITTEN_EVENT => 'onOrderWritten',
        ];
    }

    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        $this->surchargeContext->clearSurchargeData();
        $this->surchargeContext->clearVaultedCustomerId();
        $this->surchargeContext->clearPfToken();
        $this->surchargeContext->clearSelectedCardKey();

        $context = $event->getContext();
        if ($context->getScope() === "crud" || $context->getScope() === "system") {
            $salesChannelId = '';
            foreach ($event->getWriteResults() as $writeResult) {
                $payload = $writeResult->getPayload();
                if (isset($payload['salesChannelId'])) {
                    $salesChannelId = $payload['salesChannelId'];
                    $this->logger->info("Sales channel ID: " . $salesChannelId);
                    break;
                }
            }

            $orderId = $event->getIds()[0];
            if ($orderId) {
                $order = $this->orderService->getOrderDetailsById($orderId, $context);
                $paymentLinkRecord = $this->paymentLinkService->searchPaymentLink($orderId, $context);

                $orderTransaction = OrderService::latestTransaction($order);
                $salesChannelId = $salesChannelId !== '' ? $salesChannelId : (string) $order?->getSalesChannelId();

                if (!$paymentLinkRecord && $salesChannelId !== '' && $orderTransaction?->getPaymentMethod()?->getHandlerIdentifier() === LinkPayment::class) {
                    $this->dispatcher->removeSubscriber($this);
                    $this->blueSnapTransactionService->addTransaction($orderId, $orderTransaction->getPaymentMethod()->getName(), $orderId, TransactionStatuses::PENDING->value, $context, null, $orderTransaction->getId());
                    $paymentLink = $this->paymentLinkService->generatePaymentLink($order, 'payment-link-success', 'payment-link-fail', $context, false, $salesChannelId);
                    $this->paymentLinkService->storePaymentLink($orderId, $paymentLink, $context);
                    $this->paymentLinkService->sendEmail($paymentLink, $order, $salesChannelId, $context);
                }
            }
        }
    }
}
