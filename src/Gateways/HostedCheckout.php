<?php

declare(strict_types=1);

namespace BlueSnap\Gateways;

use BlueSnap\Library\Constants\TransactionStatuses;
use BlueSnap\Service\BlueSnapApiClient;
use BlueSnap\Service\BlueSnapTransactionService;
use BlueSnap\Service\OrderService;
use BlueSnap\Service\PaymentLinkService;
use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class HostedCheckout implements AsynchronousPaymentHandlerInterface
{
    private OrderService $orderService;
    private PaymentLinkService $paymentLinkService;

    private BlueSnapTransactionService $blueSnapTransactionService;
    private LoggerInterface $logger;


    public function __construct(
        OrderService $orderService,
        PaymentLinkService $paymentLinkService,
        BlueSnapTransactionService $blueSnapTransactionService,
        LoggerInterface $logger,
    ) {
        $this->orderService               = $orderService;
        $this->paymentLinkService         = $paymentLinkService;
        $this->blueSnapTransactionService = $blueSnapTransactionService;
        $this->logger                     = $logger;
    }

    public function pay(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        $redirectUrl = $this->sendReturnUrlToExternalGateway($transaction, $salesChannelContext);
        return new RedirectResponse($redirectUrl);
    }

    public function finalize(AsyncPaymentTransactionStruct $transaction, Request $request, SalesChannelContext $salesChannelContext): void
    {
        // Nothing here
    }

    private function sendReturnUrlToExternalGateway(AsyncPaymentTransactionStruct $transaction, SalesChannelContext $salesChannelContext): string
    {
        $orderId           = $transaction->getOrder()->getId();
        $orderDetail       = $this->orderService->getOrderDetailsById($orderId, $salesChannelContext->getContext());
        $successUrl        = 'checkout/finish?orderId=' . $orderId;
        $failedUrl         = 'checkout/confirm?redirected=0';
        $paymentMethodName = $salesChannelContext->getPaymentMethod()->getName();
        $this->blueSnapTransactionService->addTransaction($orderId, $paymentMethodName, $orderId, TransactionStatuses::PENDING->value, $salesChannelContext->getContext());
        return $this->paymentLinkService->generatePaymentLink($orderDetail, $successUrl, $failedUrl, false, $salesChannelContext->getSalesChannelId());
    }
}
