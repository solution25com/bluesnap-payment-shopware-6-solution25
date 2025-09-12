<?php declare(strict_types=1);

namespace solu1BluesnapPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1750339398VaultedShopper extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1750339398;
    }

    public function update(Connection $connection): void
    {
        $sql = /** @lang text */
            <<<SQL
        CREATE TABLE IF NOT EXISTS `solu1_bluesnap_vaulted_shopper` (
            `id` BINARY(16) NOT NULL,
            `customer_id` Binary(16) NOT NULL,
            `vaulted_shopper_id` VARCHAR(255) NOT NULL,
            `card_type` varchar(255) DEFAULT NULL,
            `created_at` DATETIME(3),
            `updated_at` DATETIME(3) DEFAULT NULL,
            PRIMARY KEY (`id`),
            CONSTRAINT `fk.solu1_bluesnap_vaulted_shopper.customer_id` FOREIGN KEY (`customer_id`)
            REFERENCES `customer` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
        )
            ENGINE = InnoDB
            DEFAULT CHARSET = utf8mb4
            COLLATE = utf8mb4_unicode_ci;
        SQL;

        $connection->executeStatement($sql);
    }
}
