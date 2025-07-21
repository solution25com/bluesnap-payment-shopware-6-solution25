<?php

namespace BlueSnap\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class OrderService
{
  private EntityRepository $orderRepository;
  private EntityRepository $orderTransactionRepository;

  public function __construct(
    EntityRepository             $orderRepository,
    EntityRepository             $orderTransactionRepository,
  )
  {
    $this->orderRepository = $orderRepository;
    $this->orderTransactionRepository = $orderTransactionRepository;
  }

  public function getOrderDetailsById(string $orderId, Context $context)
  {
    $criteria = new Criteria([$orderId]);
    $criteria->addAssociation('lineItems');
    $criteria->addAssociation('transactions');
    $criteria->addAssociation('transactions.paymentMethod');
    $criteria->addAssociation('currency');
    return $this->orderRepository->search($criteria, $context)->first();
  }

  public function getOrderTransactionIdByOrderId($orderId, $context)
  {
    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('orderId', $orderId));
    $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();
    if ($orderTransaction) {
      return $orderTransaction->getId();
    }
    return null;
  }

  public function updateOrderCustomFields(Entity $order, float|int $orderAmount, $orderId, Context $context)
  {
    $customFields = $order->getCustomFields() ?? [];
    $customFields['returnAmountCapture'] = $orderAmount;

    $this->orderRepository->update([
      [
        'id' => $orderId,
        'customFields' => $customFields,
      ],
    ], $context);
  }
}
