<?php

declare(strict_types=1);

namespace BlueSnap\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1789606800AddOrderTransactionId extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1789606800;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `bluesnap_transaction`');

        if (in_array('order_transaction_id', $columns, true)) {
            return;
        }

        $connection->executeStatement(
            /** @lang text */
            'ALTER TABLE `bluesnap_transaction`
                ADD COLUMN `order_transaction_id` BINARY(16) NULL,
                ADD COLUMN `order_transaction_version_id` BINARY(16) NULL,
                ADD KEY `idx.bluesnap_transaction.order_transaction`
                    (`order_transaction_id`, `order_transaction_version_id`),
                ADD CONSTRAINT `fk.bluesnap_transaction.order_transaction_id`
                    FOREIGN KEY (`order_transaction_id`, `order_transaction_version_id`)
                    REFERENCES `order_transaction` (`id`, `version_id`)
                    ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
