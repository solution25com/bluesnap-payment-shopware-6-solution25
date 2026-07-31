<?php

declare(strict_types=1);

namespace BlueSnap\Storefront\Controller;

use BlueSnap\Gateways\HostedCheckout;
use BlueSnap\Gateways\LinkPayment;
use BlueSnap\Library\Constants\TransactionStatuses;
use BlueSnap\Service\BlueSnapConfig;
use BlueSnap\Service\BlueSnapTransactionService;
use BlueSnap\Service\OrderService;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Order\RecalculationService;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Context;
use Psr\Log\LoggerInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class WebhookController
{
    private OrderService $orderService;
    private OrderTransactionStateHandler $transactionStateHandler;
    private BlueSnapTransactionService $blueSnapTransactionService;
    private BlueSnapConfig $blueSnapConfig;
    private EntityRepository $orderLineItemRepository;
    private EntityRepository $orderRepository;
    private RecalculationService $recalculationService;
    private LoggerInterface $logger;

    public function __construct(
        OrderService $orderService,
        OrderTransactionStateHandler $transactionStateHandler,
        BlueSnapTransactionService $blueSnapTransactionService,
        BlueSnapConfig $blueSnapConfig,
        EntityRepository $orderLineItemRepository,
        EntityRepository $orderRepository,
        RecalculationService $recalculationService,
        LoggerInterface $logger
    ) {
        $this->orderService = $orderService;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->blueSnapTransactionService = $blueSnapTransactionService;
        $this->blueSnapConfig = $blueSnapConfig;
        $this->orderLineItemRepository = $orderLineItemRepository;
        $this->orderRepository = $orderRepository;
        $this->recalculationService = $recalculationService;
        $this->logger = $logger;
    }

    #[Route(path: '/webhook', name: 'api.webhook', methods: ['POST', 'GET'])]
    public function webhook(Request $request, SalesChannelContext $context): JsonResponse
    {

        $rawData = $request->getContent();
        parse_str($rawData, $params);

        $this->logger->info(json_encode($rawData));

        $transactionType = $params['transactionType'] ?? '';
        $transactionId = $params['merchantTransactionId'] ?? ''; // Same as orderID
        $captureReferenceNumber = $params['captureReferenceNumber'] ?? '';

        $enabledThreeD = $this->blueSnapConfig->getConfig('threeDS', $context->getSalesChannelId());
        if ($enabledThreeD) {
            $threeD = $params['3DStatus'] ?? '';
            if ($threeD !== 'AUTHENTICATION_SUCCEEDED') {
                return new JsonResponse(['status' => false]);
            }
        }

        if ($transactionType !== 'CHARGE') {
            return new JsonResponse(['status' => false]);
        }

        if (!$transactionId) {
            return new JsonResponse(['status' => false]);
        }

        $order = $this->orderService->getOrderDetailsById($transactionId, $context->getContext());
        if (!$order) {
            return new JsonResponse(['status' => false]);
        }

        $transaction = $order->getTransactions()->first();
        if (!$transaction) {
            return new JsonResponse(['status' => false]);
        }

        $handlerIdentifier = $transaction->getPaymentMethod()->getHandlerIdentifier();

        if ($handlerIdentifier != LinkPayment::class && $handlerIdentifier != HostedCheckout::class) {
            return new JsonResponse(['status' => false]);
        }

        if ($handlerIdentifier == LinkPayment::class || $handlerIdentifier == HostedCheckout::class) {
            try {
                $this->applySurchargeFromIpn($order, $params, $context);
            } catch (\Throwable $e) {
                $this->logger->error('Surcharge application failed: ' . $e->getMessage());
            }

            $this->markTransactionPaid($transaction, $order->getId(), $captureReferenceNumber, $context->getContext());

            return new JsonResponse(['status' => true]);
        }

        return new JsonResponse(['status' => false]);
    }

    private function markTransactionPaid($transaction, string $orderId, string $captureReferenceNumber, Context $context): void
    {
        try {
            $this->transactionStateHandler->paid($transaction->getId(), $context);
        } catch (\Throwable $e) {
            $this->logger->info('paid() skipped: ' . $e->getMessage());
        }

        $this->blueSnapTransactionService->updateTransactionStatus($orderId, TransactionStatuses::PAID->value, $context, $captureReferenceNumber);
    }

    private function applySurchargeFromIpn($order, array $params, SalesChannelContext $context): void
    {
        if ($this->orderHasSurcharge($order->getId(), $context->getContext())) {
            return;
        }

        $totalCharged = (float) str_replace(',', '', $params['invoiceChargeAmount'] ?? '0');
        $mainProduct  = (float) str_replace(['$', ','], '', (string)($params['Main_product'] ?? '0'));
        $surchargeAmount = round($totalCharged - $mainProduct, 2);

        if ($surchargeAmount <= 0 || $mainProduct <= 0) {
            return;
        }

        $lineItem = (new LineItem('bluesnap-surcharge', LineItem::CUSTOM_LINE_ITEM_TYPE, null, 1))
            ->setLabel('Payment Surcharge')
            ->setGood(false)
            ->setRemovable(false)
            ->setStackable(false)
            ->setPriceDefinition(new QuantityPriceDefinition($surchargeAmount, new TaxRuleCollection(), 1));

        $orderId = $order->getId();
        $orderRepo = $this->orderRepository;
        $recalc = $this->recalculationService;

        $context->getContext()->scope(Context::SYSTEM_SCOPE, function (Context $systemContext) use ($orderRepo, $recalc, $orderId, $lineItem) {
            $versionId = $orderRepo->createVersion($orderId, $systemContext);
            $versionContext = $systemContext->createWithVersionId($versionId);
            $recalc->addCustomLineItem($orderId, $lineItem, $versionContext);
            $orderRepo->merge($versionId, $systemContext);
        });
    }

    private function orderHasSurcharge(string $orderId, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addFilter(new EqualsFilter('identifier', 'bluesnap-surcharge'));
        $criteria->setLimit(1);

        return $this->orderLineItemRepository->searchIds($criteria, $context)->getTotal() > 0;
    }
}
