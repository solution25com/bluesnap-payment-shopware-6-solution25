<?php

declare(strict_types=1);

namespace solu1BluesnapPayment\Gateways;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use solu1BluesnapPayment\Library\Constants\TransactionStatuses;
use solu1BluesnapPayment\Service\BlueSnapTransactionService;
use solu1BluesnapPayment\Service\OrderService;
use solu1BluesnapPayment\Service\PaymentLinkService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;


class HostedCheckout extends AbstractPaymentHandler
{
  private OrderService $orderService;
  private PaymentLinkService $paymentLinkService;

  private BlueSnapTransactionService $blueSnapTransactionService;

  private OrderTransactionStateHandler $transactionStateHandler;
  private LoggerInterface $logger;


  public function __construct(
    OrderService                 $orderService,
    PaymentLinkService           $paymentLinkService,
    BlueSnapTransactionService   $blueSnapTransactionService,
    OrderTransactionStateHandler $transactionStateHandler,
    LoggerInterface              $logger,
  )
  {
    $this->orderService = $orderService;
    $this->paymentLinkService = $paymentLinkService;
    $this->blueSnapTransactionService = $blueSnapTransactionService;
    $this->transactionStateHandler = $transactionStateHandler;
    $this->logger = $logger;
  }

  public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
  {
    // This payment handler does not support recurring payments nor refunds
    return false;
  }

  public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
  {

    $orderTransaction = $this->orderService->getOrderTransactionsById($transaction->getOrderTransactionId(), $context);
    if (!$orderTransaction) {
      $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
      throw new \RuntimeException('OrderTransaction not found for ID ' . $transaction->getOrderTransactionId());
    }

    $salesChannelId = $request->attributes->get('sw-sales-channel-id');
    $redirectUrl = $this->sendReturnUrlToExternalGateway($orderTransaction, $salesChannelId, $context);
    return new RedirectResponse($redirectUrl);
  }


  private function sendReturnUrlToExternalGateway(OrderTransactionEntity $orderTransaction, string $salesChannelId, Context $context): string
  {
    $order = $orderTransaction->getOrder();
    $orderId = $order->getId();
    $orderDetail = $this->orderService->getOrderDetailsById($orderId, $context);
    $successUrl = 'checkout/finish?orderId=' . $orderId;
    $failedUrl = 'checkout/confirm?redirected=0';

    $paymentMethodName = $orderTransaction->getPaymentMethod()->getName();


    $this->blueSnapTransactionService->addTransaction($orderId, $paymentMethodName, $orderId, TransactionStatuses::PENDING->value, $context);
    return $this->paymentLinkService->generatePaymentLink($orderDetail, $successUrl, $failedUrl, $context, false, $salesChannelId);
  }
}
