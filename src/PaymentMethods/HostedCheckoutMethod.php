<?php

namespace BlueSnap\PaymentMethods;

use BlueSnap\Gateways\HostedCheckout;

class HostedCheckoutMethod implements PaymentMethodInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'BlueSnap Direct Debit (Hosted Checkout)';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return '(Hosted Checkout) BlueSnap Payment';
    }

    /**
     * @inheritDoc
     */
    public function getPaymentHandler(): string
    {
        return HostedCheckout::class;
    }
}
