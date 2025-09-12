<?php

namespace solu1BluesnapPayment\Gateways;

use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class LinkPayment extends AbstractPaymentHandler
{
  public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
  {
    // This payment handler does not support recurring payments nor refunds
    return false;
  }
  public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
  {
    return new RedirectResponse('/');
  }
}
