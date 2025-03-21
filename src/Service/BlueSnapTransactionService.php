<?php

namespace BlueSnap\Service;

use BlueSnap\Core\Content\Transaction\BluesnapTransactionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class BlueSnapTransactionService
{
    private EntityRepository $blueSnapTransactionRepository;
    private EntityRepository $orderRepository;

    public function __construct(EntityRepository $blueSnapTransactionRepository, EntityRepository $orderRepository)
    {
        $this->blueSnapTransactionRepository = $blueSnapTransactionRepository;
        $this->orderRepository               = $orderRepository;
    }

    public function updateTransactionStatus($orderId, $status, $context, $captureReferenceNumber = '')
    {
        $transaction = $this->getTransactionByOrderId($orderId, $context);

        $this->blueSnapTransactionRepository->update([
          [
            'id'            => $transaction->getId(),
            'status'        => $status,
            'transactionId' => $captureReferenceNumber != '' ? $captureReferenceNumber : $transaction->getTransactionId(),
            'updatedAt'     => (new \DateTime())->format('Y-m-d H:i:s')
          ]
        ], $context);

        $this->orderRepository->upsert([[
          'id'                  => $orderId,
          'bluesnapTransaction' => [
            'data' => [
              'id'                    => $transaction->getId(),
              'blueSnapTransactionId' => $captureReferenceNumber != '' ? $captureReferenceNumber : $transaction->getTransactionId(),
              'paymentMethodName'     => $transaction->getPaymentMethodName(),
              'status'                => $status,
            ]
          ]
        ]], $context);
    }

    public function addTransaction($orderId, $paymentMethodName, $transactionId, $status, $context): void
    {
        $tableBlueSnapId = Uuid::randomHex();
        $this->blueSnapTransactionRepository->upsert([
          [
            'id'                => $tableBlueSnapId,
            'orderId'           => $orderId,
            'paymentMethodName' => $paymentMethodName,
            'transactionId'     => $transactionId,
            'status'            => $status,
            'createdAt'         => (new \DateTime())->format('Y-m-d H:i:s')
          ]
        ], $context);

        $this->orderRepository->upsert([[
          'id'                  => $orderId,
          'bluesnapTransaction' => [
            'data' => [
              'id'                    => $tableBlueSnapId,
              'blueSnapTransactionId' => $transactionId,
              'paymentMethodName'     => $paymentMethodName,
              'status'                => $status,
            ]
          ]
        ]], $context);
    }

    public function getTransactionByOrderId(string $orderId, Context $context): null|Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        try {
            return $this->blueSnapTransactionRepository->search($criteria, $context)->first();
        } catch (\Exception $e) {
            return null;
        }
    }
}
