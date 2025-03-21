<?php

declare(strict_types=1);

namespace BlueSnap\Core\Content\Transaction;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;

class BluesnapTransactionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'bluesnap_transaction';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return BluesnapTransactionEntity::class;
    }

    public function getCollectionClass(): string
    {
        return BluesnapTransactionCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
          (new IdField('id', 'id'))->addFlags(new ApiAware(), new Required(), new PrimaryKey()),
          (new StringField('order_id', 'orderId'))->addFlags(new ApiAware()),
          (new StringField('payment_method_name', 'paymentMethodName'))->addFlags(new ApiAware()),
          (new StringField('transaction_id', 'transactionId'))->addFlags(new ApiAware(), new Required()),
          (new StringField('status', 'status'))->addFlags(new ApiAware(), new Required()),
        ]);
    }
}
