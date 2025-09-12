<?php

declare(strict_types=1);

namespace solu1BluesnapPayment\Storefront\Controller;

use solu1BluesnapPayment\Gateways\HostedCheckout;
use solu1BluesnapPayment\Gateways\LinkPayment;
use solu1BluesnapPayment\Library\Constants\TransactionStatuses;
use solu1BluesnapPayment\Service\BlueSnapConfig;
use solu1BluesnapPayment\Service\BlueSnapTransactionService;
use solu1BluesnapPayment\Service\OrderService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
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
  private LoggerInterface $logger;

  public function __construct(
    OrderService                 $orderService,
    OrderTransactionStateHandler $transactionStateHandler,
    BlueSnapTransactionService   $blueSnapTransactionService,
    BlueSnapConfig               $blueSnapConfig,
    LoggerInterface              $logger
  )
  {
    $this->orderService = $orderService;
    $this->transactionStateHandler = $transactionStateHandler;
    $this->blueSnapTransactionService = $blueSnapTransactionService;
    $this->blueSnapConfig = $blueSnapConfig;
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
    $transaction = $order->getTransactions()->first();

    if ($transaction->getPaymentMethod()->getHandlerIdentifier() != LinkPayment::class && $transaction->getPaymentMethod()->getHandlerIdentifier() != HostedCheckout::class) {
      return new JsonResponse(['status' => false]);
    }

    $this->transactionStateHandler->paid($transaction->getId(), $context->getContext());

    $this->blueSnapTransactionService->updateTransactionStatus($order->getId(), TransactionStatuses::PAID->value, $context->getContext(), $captureReferenceNumber);
    return new JsonResponse(['status' => true]);
  }
}
