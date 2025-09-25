<?php declare(strict_types=1);

namespace BlueSnap\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1750339649BlueSnapPaymentLinkTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1750339649;
    }

    public function update(Connection $connection): void
    {
        $query = <<<SQL
        CREATE TABLE IF NOT EXISTS `bluesnap_payment_link` (
                `id` BINARY(16) NOT NULL,
                `order_id` VARCHAR(255) NOT NULL,
                `link` LONGTEXT NOT NULL,
                `created_at` DATETIME(3),
                `updated_at` DATETIME(3) DEFAULT NULL,
                PRIMARY KEY (`id`)
        )
            ENGINE = InnoDB
            DEFAULT CHARSET = utf8mb4
            COLLATE = utf8mb4_unicode_ci;
        SQL;

        $connection->executeStatement($query);
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS `bluesnap_payment_link`');
    }
}
