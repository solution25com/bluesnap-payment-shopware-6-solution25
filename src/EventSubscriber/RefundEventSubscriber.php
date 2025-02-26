<?php

namespace BlueSnap\EventSubscriber;

use BlueSnap\Library\Constants\TransactionStatuses;
use BlueSnap\Service\BlueSnapApiClient;
use BlueSnap\Service\BlueSnapTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;

class RefundEventSubscriber implements EventSubscriberInterface
{

  private BlueSnapTransactionService $blueSnapTransactionService;

  private BlueSnapApiClient $blueSnapApiClient;

  private EntityRepository $orderTransactionRepository;
  private OrderTransactionStateHandler $transactionStateHandler;

  private LoggerInterface $logger;

  public function __construct(
    BlueSnapTransactionService $blueSnapTransactionService,
    BlueSnapApiClient          $blueSnapApiClient,
    EntityRepository           $orderReturnRepository,
    EntityRepository           $orderTransactionRepository,
    OrderTransactionStateHandler $transactionStateHandler,
    LoggerInterface            $logger)
  {
    $this->blueSnapTransactionService = $blueSnapTransactionService;
    $this->blueSnapApiClient = $blueSnapApiClient;
    $this->orderReturnRepository = $orderReturnRepository;
    $this->orderTransactionRepository = $orderTransactionRepository;
    $this->transactionStateHandler = $transactionStateHandler;
    $this->logger = $logger;
  }

  public static function getSubscribedEvents()
  {
    return [
      'state_enter.order_return.state.in_progress' => 'onProgressRefund'
    ];
  }

  public function onProgressRefund(OrderStateMachineStateChangeEvent $event): void
  {
    $context = $event->getContext();
    $order = $event->getOrder();

    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('orderId', $order->getId()));
    $orderReturn = $this->orderReturnRepository->search($criteria, $context)->first();
    $orderTransactionId = $this->getOrderTransactionIdByOrderId($order->getId(), $context);
    $orderTotalAmount = $order->getAmountTotal();

    $body = [
      'reason' => 'Refund for orderNumber ' . $order->getOrderNumber(),
      'cancelSubscriptions' => false,
      'transactionMetaData' => [
        'metaData' => [
          [
            'metaValue' => $orderReturn->getAmountTotal(),
            'metaKey' => 'refundedItems',
            'metaDescription' => 'Refund Selected Items',
          ]
        ]
      ]
    ];

    $transaction = $this->blueSnapTransactionService->getTransactionByOrderId($order->getId(), $context);

    if ($transaction && $transaction->getStatus() == TransactionStatuses::PAID->value) {

      $response = $this->blueSnapApiClient->refund($transaction->getTransactionId(), $body, $event->getSalesChannelId());
      $parsedResponse = json_decode($response, true);
      if ($parsedResponse['refundStatus'] == 'SUCCESS') {
        if($orderReturn->getAmountTotal() == $orderTotalAmount) {
          $this->transactionStateHandler->refund($orderTransactionId, $context);
        }else {
          $this->transactionStateHandler->refundPartially($orderTransactionId, $context);
        }
        $this->blueSnapTransactionService->updateTransactionStatus(
          $order->getId(),
          TransactionStatuses::REFUND->value,
          $context
        );
      }
    }
  }

  private function getOrderTransactionIdByOrderId($orderId, $context)
  {
    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('orderId', $orderId));
    $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();
    if ($orderTransaction) {
      return $orderTransaction->getId();
    }
    return null;
  }
}