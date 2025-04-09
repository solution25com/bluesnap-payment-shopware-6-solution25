<?php

namespace BlueSnap\PaymentMethods;

use BlueSnap\Gateways\ApplePay;
use BlueSnap\PaymentMethods\PaymentMethodInterface;

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
