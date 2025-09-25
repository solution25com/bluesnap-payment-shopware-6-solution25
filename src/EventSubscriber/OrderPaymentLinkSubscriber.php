<?php

namespace BlueSnap\EventSubscriber;

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
    private EventDispatcherInterface $dispatcher;
    private LoggerInterface $logger;

    public function __construct(
        OrderService $orderService,
        PaymentLinkService $paymentLinkService,
        BlueSnapTransactionService $blueSnapTransactionService,
        EventDispatcherInterface $dispatcher,
        LoggerInterface $logger
    ) {
        $this->orderService               = $orderService;
        $this->paymentLinkService         = $paymentLinkService;
        $this->blueSnapTransactionService = $blueSnapTransactionService;
        $this->dispatcher                 = $dispatcher;
        $this->logger                     = $logger;
    }

    public static function getSubscribedEvents()
    {
        return [
          OrderEvents::ORDER_WRITTEN_EVENT => 'onOrderWritten',
        ];
    }

    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        if ($context->getScope() === "crud") {
            $salesChannelId = '';
            foreach ($event->getWriteResults() as $writeResult) {
                $payload = $writeResult->getPayload();
                if (isset($payload['salesChannelId'])) {
                    $salesChannelId = $payload['salesChannelId'];
                    break;
                }
            }

            $orderId = $event->getIds()[0];
            if ($orderId) {
                $order             = $this->orderService->getOrderDetailsById($orderId, $context);
                $paymentLinkRecord = $this->paymentLinkService->searchPaymentLink($orderId, $context);

                if (!$paymentLinkRecord && $order->getTransactions()->first()->getPaymentMethod()->getHandlerIdentifier() == LinkPayment::class) {
                    $this->dispatcher->removeSubscriber($this);
                    $this->blueSnapTransactionService->addTransaction($orderId, $order->getTransactions()->first()->getPaymentMethod()->getName(), $orderId, TransactionStatuses::PENDING->value, $context);
                    $paymentLink = $this->paymentLinkService->generatePaymentLink($order, 'payment-link-success', 'payment-link-fail', $context,false, $salesChannelId);
                    $this->paymentLinkService->storePaymentLink($orderId, $paymentLink, $context);
                    $this->paymentLinkService->sendEmail($paymentLink, $order, $salesChannelId, $context);
                }
            }
        }
    }
}
