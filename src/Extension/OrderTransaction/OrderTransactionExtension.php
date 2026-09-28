<?php

declare(strict_types=1);

namespace BlueSnap\Extension\OrderTransaction;

use BlueSnap\Core\Content\Transaction\BluesnapTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class OrderTransactionExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToManyAssociationField('bluesnapTransactions', BluesnapTransactionDefinition::class, 'order_transaction_id'))->addFlags(new ApiAware()),
        );
    }

    public function getDefinitionClass(): string
    {
        return OrderTransactionDefinition::class;
    }

    public function getEntityName(): string
    {
        return OrderTransactionDefinition::ENTITY_NAME;
    }
}
