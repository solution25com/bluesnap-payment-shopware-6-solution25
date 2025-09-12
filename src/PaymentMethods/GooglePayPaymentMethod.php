<?php

namespace solu1BluesnapPayment\PaymentMethods;

use solu1BluesnapPayment\Gateways\GooglePay;
use solu1BluesnapPayment\PaymentMethods\PaymentMethodInterface;

class GooglePayPaymentMethod implements PaymentMethodInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'BlueSnap Google Pay';
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
        return GooglePay::class;
    }
}
