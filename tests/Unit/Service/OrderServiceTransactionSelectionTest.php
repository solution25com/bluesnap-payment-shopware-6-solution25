<?php declare(strict_types=1);

namespace BlueSnap\Tests\Unit\Service;

use BlueSnap\Service\OrderService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Uuid\Uuid;

class OrderServiceTransactionSelectionTest extends TestCase
{
    public function testNullOrderHasNoTransaction(): void
    {
        static::assertNull(OrderService::latestTransaction(null));
    }

    public function testAnOrderWithoutTransactionsHasNone(): void
    {
        static::assertNull(OrderService::latestTransaction($this->order([])));
    }

    public function testTheOnlyTransactionIsReturned(): void
    {
        $only = $this->transaction('2026-09-01 10:00:00');

        static::assertSame($only, OrderService::latestTransaction($this->order([$only])));
    }

    /**
     * A retried order or a payment method change leaves the stale transaction first in the
     * collection, and that one can no longer change state.
     */
    public function testTheNewestTransactionWinsOverTheFirstOne(): void
    {
        $stale = $this->transaction('2026-09-01 10:00:00');
        $current = $this->transaction('2026-09-02 11:00:00');

        static::assertSame($current, OrderService::latestTransaction($this->order([$stale, $current])));
    }

    public function testTheNewestTransactionWinsRegardlessOfCollectionOrder(): void
    {
        $current = $this->transaction('2026-09-02 11:00:00');
        $stale = $this->transaction('2026-09-01 10:00:00');

        static::assertSame($current, OrderService::latestTransaction($this->order([$current, $stale])));
    }

    /** @param OrderTransactionEntity[] $transactions */
    private function order(array $transactions): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $order->setTransactions(new OrderTransactionCollection($transactions));

        return $order;
    }

    private function transaction(string $createdAt): OrderTransactionEntity
    {
        $transaction = new OrderTransactionEntity();
        $transaction->setId(Uuid::randomHex());
        $transaction->setCreatedAt(new \DateTimeImmutable($createdAt));

        return $transaction;
    }
}
