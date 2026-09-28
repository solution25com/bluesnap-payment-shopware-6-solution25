<?php

declare(strict_types=1);

namespace BlueSnap\Core\Content\Transaction;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class BluesnapTransactionEntity extends Entity
{
    use EntityIdTrait;

    protected string $orderId;
    protected string $paymentMethodName;
    protected string $transactionId;
    protected string $status;
    protected ?string $cvvResponseCode = null;
    protected ?string $avsResponseCode = null;
    protected ?string $orderTransactionId = null;
    protected ?string $orderTransactionVersionId = null;
    protected ?OrderTransactionEntity $orderTransaction = null;


    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getPaymentMethodName(): string
    {
        return $this->paymentMethodName;
    }

    public function setPaymentMethodName(string $paymentMethodName): void
    {
        $this->paymentMethodName = $paymentMethodName;
    }

    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    public function setTransactionId(string $transactionId): void
    {
        $this->transactionId = $transactionId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getCvvResponseCode(): ?string
    {
        return $this->cvvResponseCode;
    }

    public function setCvvResponseCode(?string $cvvResponseCode): void
    {
        $this->cvvResponseCode = $cvvResponseCode;
    }

    public function getAvsResponseCode(): ?string
    {
        return $this->avsResponseCode;
    }

    public function setAvsResponseCode(?string $avsResponseCode): void
    {
        $this->avsResponseCode = $avsResponseCode;
    }

    public function getOrderTransactionId(): ?string
    {
        return $this->orderTransactionId;
    }

    public function setOrderTransactionId(?string $orderTransactionId): void
    {
        $this->orderTransactionId = $orderTransactionId;
    }

    public function getOrderTransactionVersionId(): ?string
    {
        return $this->orderTransactionVersionId;
    }

    public function setOrderTransactionVersionId(?string $orderTransactionVersionId): void
    {
        $this->orderTransactionVersionId = $orderTransactionVersionId;
    }

    public function getOrderTransaction(): ?OrderTransactionEntity
    {
        return $this->orderTransaction;
    }

    public function setOrderTransaction(?OrderTransactionEntity $orderTransaction): void
    {
        $this->orderTransaction = $orderTransaction;
    }
}
