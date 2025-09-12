<?php

namespace solu1BluesnapPayment\PaymentMethods;

use solu1BluesnapPayment\Gateways\LinkPayment;
use solu1BluesnapPayment\PaymentMethods\PaymentMethodInterface;

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
