<?php

namespace BlueSnap\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

class OrderService
{
    private EntityRepository $orderRepository;
    public function __construct(EntityRepository $orderRepository)
    {
        $this->orderRepository = $orderRepository;
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
}
