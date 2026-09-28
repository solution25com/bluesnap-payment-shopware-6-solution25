<?php

declare(strict_types=1);

namespace BlueSnap\Service;

use BlueSnap\Core\Checkout\Cart\BlueSnapSurchargeCartProcessor;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Order\RecalculationService;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Keeps an order's record of a BlueSnap surcharge complete.
 */
class OrderSurchargeService
{
    public const ORDER_LINE_ITEM_ID = 'bluesnap-surcharge-order';

    public const CUSTOM_FIELD = 'bluesnap_surcharge';

    public function __construct(
        private readonly RecalculationService $recalculationService,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly EntityRepository $orderLineItemRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getSurchargeOnOrder(OrderEntity $order): float
    {
        $surcharge = 0.0;

        foreach ($order->getLineItems() ?? [] as $lineItem) {
            if (self::isSurchargeLineItem($lineItem->getType(), $lineItem->getIdentifier())) {
                $surcharge += $lineItem->getTotalPrice();
            }
        }

        return round($surcharge, 2);
    }

    /**
     * A surcharge reaches an order either from the cart, keeping the cart processor's own type, or
     * from this service, where it has to be a plain custom line item to survive recalculation.
     */
    public static function isSurchargeLineItem(?string $type, ?string $identifier): bool
    {
        return $type === BlueSnapSurchargeCartProcessor::SURCHARGE_LINE_ITEM_TYPE
            || $identifier === self::ORDER_LINE_ITEM_ID
            || $identifier === BlueSnapSurchargeCartProcessor::SURCHARGE_LINE_ITEM_ID;
    }

    /**
     * @throws \RuntimeException when the order cannot be made to match, because authorising more than
     *                           the order is worth is the failure this exists to prevent
     */
    public function applyToOrder(OrderEntity $order, string $token, float $amount, Context $context, ?string $orderTransactionId = null): void
    {
        if (abs($this->getSurchargeOnOrder($order) - $amount) > 0.001) {
            $this->syncSurchargeLineItem($order, $amount, $context);
        }

        // Also on a retry of an order that already carries the surcharge: its transaction may still
        // hold the amount from before, and the capture reads that.
        $this->syncTransactionAmount($order->getId(), $orderTransactionId, $context);

        $this->storeSurchargeOnOrder($order, $token, $amount, $context);
    }

    /**
     * The card chosen on a retry can carry a different surcharge, or none, so the order is brought to
     * exactly the quoted amount rather than only ever gaining a line item.
     */
    private function syncSurchargeLineItem(OrderEntity $order, float $amount, Context $context): void
    {
        $existingIds = [];
        foreach ($order->getLineItems() ?? [] as $lineItem) {
            if (self::isSurchargeLineItem($lineItem->getType(), $lineItem->getIdentifier())) {
                $existingIds[] = $lineItem->getId();
            }
        }

        try {
            $context->scope(Context::SYSTEM_SCOPE, function (Context $systemContext) use ($order, $amount, $existingIds): void {
                $versionId = $this->orderRepository->createVersion($order->getId(), $systemContext);
                $versionContext = $systemContext->createWithVersionId($versionId);

                if ($existingIds !== []) {
                    $this->orderLineItemRepository->delete(
                        array_map(
                            static fn (string $id): array => ['id' => $id, 'versionId' => $versionId],
                            $existingIds
                        ),
                        $versionContext
                    );
                    /* @phpstan-ignore-next-line recalculate() replaces it only from 6.8, and 6.6 is still supported */
                    $this->recalculationService->recalculateOrder($order->getId(), $versionContext);
                }

                if ($amount > 0.0) {
                    $this->recalculationService->addCustomLineItem($order->getId(), $this->buildSurchargeLineItem($amount), $versionContext);
                }

                $this->orderRepository->merge($versionId, $systemContext);
            });
        } catch (\Throwable $e) {
            $this->logger->error('BlueSnap could not apply the surcharge to the order', [
                'orderId' => $order->getId(),
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('The surcharge could not be applied to the order', 0, $e);
        }
    }

    private function buildSurchargeLineItem(float $amount): LineItem
    {
        $lineItem = new LineItem(self::ORDER_LINE_ITEM_ID, LineItem::CUSTOM_LINE_ITEM_TYPE, null, 1);
        $lineItem->setLabel('Payment Surcharge');
        $lineItem->setGood(false);
        $lineItem->setStackable(false);
        $lineItem->setRemovable(false);
        $lineItem->setPriceDefinition(new QuantityPriceDefinition($amount, new TaxRuleCollection(), 1));

        return $lineItem;
    }

    /**
     * Recalculating an order leaves its payment transaction holding the old amount, and the capture
     * reads that figure, so it is brought back in step with the order total.
     */
    private function syncTransactionAmount(string $orderId, ?string $orderTransactionId, Context $context): void
    {
        if ($orderTransactionId === null) {
            return;
        }

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search(new Criteria([$orderId]), $context)->getEntities()->first();
        $total = $order?->getAmountTotal();
        if ($total === null) {
            return;
        }

        /** @var OrderTransactionEntity|null $transaction */
        $transaction = $this->orderTransactionRepository->search(new Criteria([$orderTransactionId]), $context)->getEntities()->first();
        $amount = $transaction?->getAmount();
        if ($amount === null) {
            return;
        }

        $this->orderTransactionRepository->update([
            [
                'id' => $orderTransactionId,
                'amount' => new CalculatedPrice(
                    $total,
                    $total,
                    $amount->getCalculatedTaxes(),
                    $amount->getTaxRules(),
                    $amount->getQuantity()
                ),
            ],
        ], $context);
    }

    private function storeSurchargeOnOrder(OrderEntity $order, string $token, float $amount, Context $context): void
    {
        $customFields = $order->getCustomFields() ?? [];
        $customFields[self::CUSTOM_FIELD] = [
            'token' => $token,
            'amount' => $amount,
        ];

        $this->orderRepository->update([
            [
                'id' => $order->getId(),
                'customFields' => $customFields,
            ],
        ], $context);
    }
}
