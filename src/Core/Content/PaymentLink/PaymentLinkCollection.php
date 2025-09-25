<?php

namespace BlueSnap\Core\Content\PaymentLink;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class PaymentLinkCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return PaymentLinkEntity::class;
    }
}
