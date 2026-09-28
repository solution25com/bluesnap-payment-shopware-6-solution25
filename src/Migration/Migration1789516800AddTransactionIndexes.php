<?php

declare(strict_types=1);

namespace BlueSnap\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1789516800AddTransactionIndexes extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1789516800;
    }

    public function update(Connection $connection): void
    {
        $this->removeDuplicateTransactions($connection);

        if (!$this->transactionIndexExists($connection, 'uniq.bluesnap_transaction.transaction_id')) {
            $connection->executeStatement(
                /** @lang text */
                'ALTER TABLE `bluesnap_transaction`
                    ADD UNIQUE KEY `uniq.bluesnap_transaction.transaction_id` (`transaction_id`)'
            );
        }

        if (!$this->transactionIndexExists($connection, 'idx.bluesnap_transaction.order_id')) {
            $connection->executeStatement(
                /** @lang text */
                'ALTER TABLE `bluesnap_transaction`
                    ADD KEY `idx.bluesnap_transaction.order_id` (`order_id`)'
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function removeDuplicateTransactions(Connection $connection): void
    {
        if (!$this->hasDuplicates($connection)) {
            return;
        }

        $connection->executeStatement(
            /** @lang text */
            'CREATE TABLE IF NOT EXISTS `bluesnap_transaction_duplicate_backup` LIKE `bluesnap_transaction`'
        );

        $connection->executeStatement(
            /** @lang text */
            'INSERT IGNORE INTO `bluesnap_transaction_duplicate_backup`
             SELECT `older`.* FROM `bluesnap_transaction` AS `older`
                INNER JOIN `bluesnap_transaction` AS `newer`
                    ON `older`.`transaction_id` = `newer`.`transaction_id`
                   AND `older`.`id` <> `newer`.`id`
             WHERE COALESCE(`older`.`updated_at`, `older`.`created_at`) < COALESCE(`newer`.`updated_at`, `newer`.`created_at`)
                OR (
                    COALESCE(`older`.`updated_at`, `older`.`created_at`) = COALESCE(`newer`.`updated_at`, `newer`.`created_at`)
                    AND HEX(`older`.`id`) < HEX(`newer`.`id`)
                )'
        );

        $connection->executeStatement(
            /** @lang text */
            'DELETE `older` FROM `bluesnap_transaction` AS `older`
                INNER JOIN `bluesnap_transaction` AS `newer`
                    ON `older`.`transaction_id` = `newer`.`transaction_id`
                   AND `older`.`id` <> `newer`.`id`
             WHERE COALESCE(`older`.`updated_at`, `older`.`created_at`) < COALESCE(`newer`.`updated_at`, `newer`.`created_at`)
                OR (
                    COALESCE(`older`.`updated_at`, `older`.`created_at`) = COALESCE(`newer`.`updated_at`, `newer`.`created_at`)
                    AND HEX(`older`.`id`) < HEX(`newer`.`id`)
                )'
        );
    }

    private function hasDuplicates(Connection $connection): bool
    {
        return (bool) $connection->fetchOne(
            /** @lang text */
            'SELECT 1 FROM `bluesnap_transaction`
             GROUP BY `transaction_id` HAVING COUNT(*) > 1 LIMIT 1'
        );
    }

    private function transactionIndexExists(Connection $connection, string $indexName): bool
    {
        return (bool) $connection->fetchOne(
            /** @lang text */
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = "bluesnap_transaction"
               AND index_name = :indexName
             LIMIT 1',
            ['indexName' => $indexName]
        );
    }
}
