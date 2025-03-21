<?php

namespace BlueSnap\Gateways;

use BlueSnap\Library\Constants\TransactionStatuses;
use BlueSnap\Service\BlueSnapTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class CreditCard implements SynchronousPaymentHandlerInterface
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private BlueSnapTransactionService $blueSnapTransactionService;
    private LoggerInterface $logger;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        BlueSnapTransactionService $blueSnapTransactionService,
        LoggerInterface $logger
    ) {
        $this->transactionStateHandler    = $transactionStateHandler;
        $this->blueSnapTransactionService = $blueSnapTransactionService;
        $this->logger                     = $logger;
    }

    public function pay(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): void
    {
        $context               = $salesChannelContext->getContext();
        $bluesnapTransactionId = $dataBag->get('bluesnap_transaction_id');
        $paymentMethodName     = $salesChannelContext->getPaymentMethod()->getName();
        $orderId               = $transaction->getOrder()->getId();
        $this->transactionStateHandler->paid($transaction->getOrderTransaction()->getId(), $context);
        $this->blueSnapTransactionService->addTransaction($orderId, $paymentMethodName, $bluesnapTransactionId, TransactionStatuses::PAID->value, $context);
    }
}
