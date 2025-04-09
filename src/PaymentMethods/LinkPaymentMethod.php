<?php

namespace BlueSnap\PaymentMethods;

use BlueSnap\Gateways\LinkPayment;
use BlueSnap\PaymentMethods\PaymentMethodInterface;

class LinkPaymentMethod implements PaymentMethodInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'BlueSnap Link Payment';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'BlueSnap Link Payment';
    }

    /**
     * @inheritDoc
     */
    public function getPaymentHandler(): string
    {
        return LinkPayment::class;
    }
}
