<?php

declare(strict_types=1);

namespace BlueSnap\Core\Content\VaultedShopper;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(VaultedShopperEntity $entity)
 * @method void set(string $key, VaultedShopperEntity $entity)
 * @method VaultedShopperEntity[] getIterator()
 * @method VaultedShopperEntity[] getElements()
 * @method VaultedShopperEntity|null get(string $key)
 * @method VaultedShopperEntity|null first()
 * @method VaultedShopperEntity|null last()
 */
class VaultedShopperCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return VaultedShopperEntity::class;
    }
}
