<?php

namespace solu1BluesnapPayment\PaymentMethods;

use solu1BluesnapPayment\Gateways\HostedCheckout;

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
