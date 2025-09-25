<?php

namespace BlueSnap\PaymentMethods;

use BlueSnap\Gateways\CreditCard;

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
