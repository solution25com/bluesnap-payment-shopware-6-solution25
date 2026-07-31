<?php

namespace BlueSnap\Extension\Order;

use BlueSnap\Core\Content\Transaction\BluesnapTransactionDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class OrderExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToManyAssociationField('bluesnapTransaction', BluesnapTransactionDefinition::class, 'order_id'))->addFlags(new ApiAware()),
        );
    }

    public function getDefinitionClass(): string
    {
        return \Shopware\Core\Checkout\Order\OrderDefinition::class;
    }

    public function getEntityName(): string
    {
        return \Shopware\Core\Checkout\Order\OrderDefinition::ENTITY_NAME;
    }
}
