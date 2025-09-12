<?php

namespace solu1BluesnapPayment\PaymentMethods;

use solu1BluesnapPayment\Gateways\ApplePay;
use solu1BluesnapPayment\PaymentMethods\PaymentMethodInterface;

class ApplePayPaymentMethod implements PaymentMethodInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'BlueSnap Apple Pay';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'BlueSnap Payment';
    }

    /**
     * @inheritDoc
     */
    public function getPaymentHandler(): string
    {
        return ApplePay::class;
    }
}
