<?php

namespace BlueSnap\Core\Content\PaymentLink;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;

class PaymentLinkDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'bluesnap_payment_link';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
          (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
          (new StringField('order_id', 'order_id'))->addFlags(new Required()),
          (new LongTextField('link', 'link'))->addFlags(new Required()),
        ]);
    }
}
