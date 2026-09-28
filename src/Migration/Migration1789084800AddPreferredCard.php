<?php

declare(strict_types=1);

namespace BlueSnap\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1789084800AddPreferredCard extends MigrationStep
{
    private const TABLE = 'bluesnap_vaulted_shopper';

    public function getCreationTimestamp(): int
    {
        return 1789084800;
    }

    public function update(Connection $connection): void
    {
        $this->removeDuplicateShoppers($connection);

        if (!$this->columnIsExists($connection, 'preferred_card_type')) {
            $connection->executeStatement(
                /** @lang text */
                'ALTER TABLE `bluesnap_vaulted_shopper`
                    ADD COLUMN `preferred_card_type` VARCHAR(255) NULL AFTER `card_type`'
            );
        }

        if (!$this->columnIsExists($connection, 'preferred_card_last_four')) {
            $connection->executeStatement(
                /** @lang text */
                'ALTER TABLE `bluesnap_vaulted_shopper`
                    ADD COLUMN `preferred_card_last_four` VARCHAR(4) NULL AFTER `preferred_card_type`'
            );
        }

        if (!$this->indexIsExists($connection, 'uniq.bluesnap_vaulted_shopper.customer_id')) {
            $connection->executeStatement(
                /** @lang text */
                'ALTER TABLE `bluesnap_vaulted_shopper`
                    ADD UNIQUE KEY `uniq.bluesnap_vaulted_shopper.customer_id` (`customer_id`)'
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }


    private function hasDuplicateShoppers(Connection $connection): bool
    {
        return (bool) $connection->fetchOne(
            /** @lang text */
            'SELECT 1 FROM `bluesnap_vaulted_shopper`
             GROUP BY `customer_id` HAVING COUNT(*) > 1 LIMIT 1'
        );
    }

    private function removeDuplicateShoppers(Connection $connection): void
    {
        if (!$this->hasDuplicateShoppers($connection)) {
            return;
        }

        $connection->executeStatement(
            /** @lang text */
            'CREATE TABLE IF NOT EXISTS `bluesnap_vaulted_shopper_duplicate_backup` LIKE `bluesnap_vaulted_shopper`'
        );

        $connection->executeStatement(
            /** @lang text */
            'INSERT IGNORE INTO `bluesnap_vaulted_shopper_duplicate_backup`
             SELECT `older`.* FROM `bluesnap_vaulted_shopper` AS `older`
                INNER JOIN `bluesnap_vaulted_shopper` AS `newer`
                    ON `older`.`customer_id` = `newer`.`customer_id`
                   AND `older`.`id` <> `newer`.`id`
             WHERE COALESCE(`older`.`updated_at`, `older`.`created_at`) < COALESCE(`newer`.`updated_at`, `newer`.`created_at`)
                OR (
                    COALESCE(`older`.`updated_at`, `older`.`created_at`) = COALESCE(`newer`.`updated_at`, `newer`.`created_at`)
                    AND HEX(`older`.`id`) < HEX(`newer`.`id`)
                )'
        );

        $connection->executeStatement(
            /** @lang text */
            'DELETE `older` FROM `bluesnap_vaulted_shopper` AS `older`
                INNER JOIN `bluesnap_vaulted_shopper` AS `newer`
                    ON `older`.`customer_id` = `newer`.`customer_id`
                   AND `older`.`id` <> `newer`.`id`
             WHERE COALESCE(`older`.`updated_at`, `older`.`created_at`) < COALESCE(`newer`.`updated_at`, `newer`.`created_at`)
                OR (
                    COALESCE(`older`.`updated_at`, `older`.`created_at`) = COALESCE(`newer`.`updated_at`, `newer`.`created_at`)
                    AND HEX(`older`.`id`) < HEX(`newer`.`id`)
                )'
        );
    }

    private function columnIsExists(Connection $connection, string $column): bool
    {
        return (bool) $connection->fetchOne(
            /** @lang text */
            'SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :table AND `COLUMN_NAME` = :column',
            ['table' => self::TABLE, 'column' => $column]
        );
    }

    private function indexIsExists(Connection $connection, string $index): bool
    {
        return (bool) $connection->fetchOne(
            /** @lang text */
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :table AND `INDEX_NAME` = :index',
            ['table' => self::TABLE, 'index' => $index]
        );
    }
}
