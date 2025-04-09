<?php

declare(strict_types=1);

namespace BlueSnap\Core\Content\Transaction;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(BluesnapTransactionEntity $entity)
 * @method void set(string $key, BluesnapTransactionEntity $entity)
 * @method BluesnapTransactionEntity[] getIterator()
 * @method BluesnapTransactionEntity[] getElements()
 * @method BluesnapTransactionEntity|null get(string $key)
 * @method BluesnapTransactionEntity|null first()
 * @method BluesnapTransactionEntity|null last()
 */
class BluesnapTransactionCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BluesnapTransactionEntity::class;
    }
}
