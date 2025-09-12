<?php

namespace solu1BluesnapPayment\PaymentMethods;

use solu1BluesnapPayment\Gateways\CreditCard;

class CreditCardPaymentMethod implements PaymentMethodInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'BlueSnap Credit Card';
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
        return CreditCard::class;
    }
}
