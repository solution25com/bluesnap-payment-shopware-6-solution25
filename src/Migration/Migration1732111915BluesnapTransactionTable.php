<?php

declare(strict_types=1);

namespace BlueSnap\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
#[Package('core')]
class Migration1732111915BluesnapTransactionTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1732111915;
    }

    public function update(Connection $connection): void
    {
        $sql = /** @lang text */
          <<<SQL
        CREATE TABLE IF NOT EXISTS `bluesnap_transaction` (
            `id` BINARY(16) NOT NULL,
            `order_id` VARCHAR(255) NOT NULL,
            `payment_method_name` varchar(255),
            `transaction_id` varchar(255) NOT NULL,
            `status` varchar(255) NOT NULL DEFAULT 'pending',
            `created_at` DATETIME(3),
            `updated_at` DATETIME(3) DEFAULT NULL,
            PRIMARY KEY (`id`)
        )
            ENGINE = InnoDB
            DEFAULT CHARSET = utf8mb4
            COLLATE = utf8mb4_unicode_ci;
        SQL;

        $connection->executeStatement($sql);
    }
}
