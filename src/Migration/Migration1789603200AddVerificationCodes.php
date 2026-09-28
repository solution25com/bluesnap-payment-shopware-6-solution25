<?php

declare(strict_types=1);

namespace BlueSnap\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1789603200AddVerificationCodes extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1789603200;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `bluesnap_transaction`');

        if (!in_array('cvv_response_code', $columns, true)) {
            $connection->executeStatement(
                /** @lang text */
                'ALTER TABLE `bluesnap_transaction` ADD COLUMN `cvv_response_code` VARCHAR(32) NULL'
            );
        }

        if (!in_array('avs_response_code', $columns, true)) {
            $connection->executeStatement(
                /** @lang text */
                'ALTER TABLE `bluesnap_transaction` ADD COLUMN `avs_response_code` VARCHAR(32) NULL'
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
