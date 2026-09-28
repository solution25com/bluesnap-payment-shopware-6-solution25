<?php

namespace BlueSnap\Service;

use BlueSnap\Core\Content\Transaction\BluesnapTransactionEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

class BlueSnapTransactionService
{
    private EntityRepository $blueSnapTransactionRepository;
    private EntityRepository $orderRepository;
    private LoggerInterface $logger;

    public function __construct(EntityRepository $blueSnapTransactionRepository, EntityRepository $orderRepository, LoggerInterface $logger)
    {
        $this->blueSnapTransactionRepository = $blueSnapTransactionRepository;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    public function updateTransactionStatus($orderId, $status, $context, $captureReferenceNumber = '', ?string $orderTransactionId = null): void
    {
        /** @var BluesnapTransactionEntity|null $transaction */
        $transaction = $this->getTransactionByOrderId($orderId, $context);
        if ($transaction === null) {
            $this->logger->warning('BlueSnap transaction not found for order ID: ' . $orderId);
            return;
        }

        $payload = [
            'id' => $transaction->getId(),
            'status' => $status,
            'transactionId' => $captureReferenceNumber != '' ? $captureReferenceNumber : $transaction->getTransactionId(),
            'updatedAt' => (new \DateTime())->format('Y-m-d H:i:s')
        ];

        if ($orderTransactionId !== null && $orderTransactionId !== '') {
            $payload['orderTransactionId'] = $orderTransactionId;
            $payload['orderTransactionVersionId'] = $context->getVersionId();
        }

        $this->blueSnapTransactionRepository->update([$payload], $context);

        $this->orderRepository->upsert([[
            'id' => $orderId,
            'bluesnapTransaction' => [
                'data' => [
                    'id' => $transaction->getId(),
                    'blueSnapTransactionId' => $captureReferenceNumber != '' ? $captureReferenceNumber : $transaction->getTransactionId(),
                    'paymentMethodName' => $transaction->getPaymentMethodName(),
                    'status' => $status,
                ]
            ]
        ]], $context);
    }

    /**
     * @param array{cvvResponseCode?: string|null, avsResponseCode?: string|null}|null $verification
     */
    public function addTransaction($orderId, $paymentMethodName, $transactionId, $status, $context, ?array $verification = null, ?string $orderTransactionId = null): void
    {
        $existing = $this->findByTransactionId((string) $transactionId, $context);

        // The upsert below would otherwise move a provider transaction that already belongs to
        // another order, which is how a single successful charge could settle two orders.
        if ($existing !== null && $existing->getOrderId() !== $orderId) {
            $this->logger->error('BlueSnap transaction is already recorded against another order', [
                'transactionId' => $transactionId,
                'recordedOrderId' => $existing->getOrderId(),
                'requestedOrderId' => $orderId,
            ]);

            throw new \RuntimeException('BlueSnap transaction already belongs to another order');
        }

        $tableBlueSnapId = $existing?->getId() ?? Uuid::randomHex();

        $payload = [
            'id' => $tableBlueSnapId,
            'orderId' => $orderId,
            'paymentMethodName' => $paymentMethodName,
            'transactionId' => $transactionId,
            'status' => $status,
            'createdAt' => (new \DateTime())->format('Y-m-d H:i:s')
        ];

        foreach (['cvvResponseCode', 'avsResponseCode'] as $field) {
            $value = $verification[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $payload[$field] = $value;
            }
        }

        // The foreign key spans both columns, so the version has to travel with the id or the
        // reference is only half written and the constraint stops protecting the row.
        if ($orderTransactionId !== null && $orderTransactionId !== '') {
            $payload['orderTransactionId'] = $orderTransactionId;
            $payload['orderTransactionVersionId'] = $context->getVersionId();
        }

        $this->blueSnapTransactionRepository->upsert([$payload], $context);

        $this->orderRepository->upsert([[
            'id' => $orderId,
            'bluesnapTransaction' => [
                'data' => [
                    'id' => $tableBlueSnapId,
                    'blueSnapTransactionId' => $transactionId,
                    'paymentMethodName' => $paymentMethodName,
                    'status' => $status,
                ]
            ]
        ]], $context);
    }

    public function getTransactionByOrderId(string $orderId, Context $context): ?BluesnapTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        try {
            /** @var BluesnapTransactionEntity|null $transaction */
            $transaction = $this->blueSnapTransactionRepository->search($criteria, $context)->getEntities()->first();

            return $transaction;
        } catch (\Exception $e) {
            $this->logger->error('BlueSnap transaction lookup failed: ' . $e->getMessage(), ['orderId' => $orderId]);

            return null;
        }
    }

    public function findByTransactionId(string $transactionId, Context $context): ?BluesnapTransactionEntity
    {
        if ($transactionId === '') {
            return null;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('transactionId', $transactionId));
        $criteria->setLimit(1);

        /** @var BluesnapTransactionEntity|null $transaction */
        $transaction = $this->blueSnapTransactionRepository->search($criteria, $context)->getEntities()->first();

        return $transaction;
    }

    /**
     * True if a BlueSnap transaction ID is already recorded against any order, preventing the
     * same real (verified) transaction reference from being replayed onto a different order.
     */
    public function transactionIdAlreadyUsed(string $transactionId, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('transactionId', $transactionId));
        $criteria->setLimit(1);

        return $this->blueSnapTransactionRepository->searchIds($criteria, $context)->getTotal() > 0;
    }
}
