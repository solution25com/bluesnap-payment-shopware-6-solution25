<?php

namespace BlueSnap\PaymentMethods;

class PaymentMethods
{
    public const PAYMENT_METHODS = [
      CreditCardPaymentMethod::class,
      GooglePayPaymentMethod::class,
      ApplePayPaymentMethod::class,
      LinkPaymentMethod::class,
      HostedCheckoutMethod::class,
    ];
}
