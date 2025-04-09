<?php

declare(strict_types=1);

namespace BlueSnap\Storefront\Controller;

use BlueSnap\Gateways\HostedCheckout;
use BlueSnap\Gateways\LinkPayment;
use BlueSnap\Library\Constants\TransactionStatuses;
use BlueSnap\Service\BlueSnapConfig;
use BlueSnap\Service\BlueSnapTransactionService;
use BlueSnap\Service\OrderService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
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
    private LoggerInterface $logger;

    public function __construct(
        OrderService $orderService,
        OrderTransactionStateHandler $transactionStateHandler,
        BlueSnapTransactionService $blueSnapTransactionService,
        BlueSnapConfig $blueSnapConfig,
        LoggerInterface $logger
    ) {
        $this->orderService               = $orderService;
        $this->transactionStateHandler    = $transactionStateHandler;
        $this->blueSnapTransactionService = $blueSnapTransactionService;
        $this->blueSnapConfig             = $blueSnapConfig;
        $this->logger                     = $logger;
    }

    #[Route(path: '/webhook', name: 'api.webhook', methods: ['POST', 'GET'])]
    public function webhook(Request $request, Context $context): JsonResponse
    {
        $rawData = $request->getContent();
        parse_str($rawData, $params);


        $this->logger->info(json_encode($rawData));

        $threeD                 = $params['3DStatus']               ?? '';
        $transactionType        = $params['transactionType']        ?? '';
        $transactionId          = $params['merchantTransactionId']  ?? ''; // Same as orderID
        $captureReferenceNumber = $params['captureReferenceNumber'] ?? '';

        if ($threeD !== 'AUTHENTICATION_SUCCEEDED') {
            return new JsonResponse(['status' => false]);
        }

        if ($transactionType !== 'CHARGE') {
            return new JsonResponse(['status' => false]);
        }

        if (!$transactionId) {
            return new JsonResponse(['status' => false]);
        }

        $order       = $this->orderService->getOrderDetailsById($transactionId, $context);
        $transaction = $order->getTransactions()->first();

        if ($transaction->getPaymentMethod()->getHandlerIdentifier() != LinkPayment::class && $transaction->getPaymentMethod()->getHandlerIdentifier() != HostedCheckout::class) {
            return new JsonResponse(['status' => false]);
        }

        $this->transactionStateHandler->paid($transaction->getId(), $context);

        $this->blueSnapTransactionService->updateTransactionStatus($order->getId(), TransactionStatuses::PAID->value, $context, $captureReferenceNumber);
        return new JsonResponse(['status' => true]);
    }
}
