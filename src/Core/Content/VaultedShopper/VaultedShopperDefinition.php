<?php

declare(strict_types=1);

namespace BlueSnap\Core\Content\VaultedShopper;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class VaultedShopperDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'bluesnap_vaulted_shopper';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return VaultedShopperEntity::class;
    }

    public function getCollectionClass(): string
    {
        return VaultedShopperCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('customer_id', 'customerId', CustomerDefinition::class))->addFlags(new Required()),
            (new StringField('vaulted_shopper_id', 'vaultedShopperId'))->addFlags(new Required()),
            new StringField('card_type', 'cardType'),
            new StringField('preferred_card_type', 'preferredCardType'),
            new StringField('preferred_card_last_four', 'preferredCardLastFour'),
            new CreatedAtField(),
            new UpdatedAtField(),
            new OneToOneAssociationField('customer', 'customer_id', 'id', CustomerDefinition::class, false),
        ]);
    }
}
