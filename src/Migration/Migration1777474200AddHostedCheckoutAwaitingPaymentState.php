<?php

declare(strict_types=1);

namespace BlueSnap\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;

/**
 * @internal
 */
class Migration1777474200AddHostedCheckoutAwaitingPaymentState extends MigrationStep
{
    private const STATE_AWAITING_CONFIRMATION = 'bluesnap_awaiting_confirmation';
    private const ACTION_AWAIT_CONFIRMATION = 'bluesnap_await_confirmation';
    private const PAYMENT_STATE_MACHINE = 'order_transaction.state';

    public function getCreationTimestamp(): int
    {
        return 1777474200;
    }

    public function update(Connection $connection): void
    {
        $stateMachineId = $connection->fetchOne(
            'SELECT id FROM state_machine WHERE technical_name = :technicalName',
            ['technicalName' => self::PAYMENT_STATE_MACHINE]
        );

        if (!$stateMachineId) {
            return;
        }

        $stateId = $this->getStateId($connection, $stateMachineId, self::STATE_AWAITING_CONFIRMATION);
        if ($stateId === null) {
            $stateId = Uuid::randomBytes();
            $connection->executeStatement(
                'INSERT INTO state_machine_state (id, state_machine_id, technical_name, created_at)
                 VALUES (:id, :stateMachineId, :technicalName, :createdAt)',
                [
                    'id' => $stateId,
                    'stateMachineId' => $stateMachineId,
                    'technicalName' => self::STATE_AWAITING_CONFIRMATION,
                    'createdAt' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ],
                [
                    'id' => ParameterType::BINARY,
                    'stateMachineId' => ParameterType::BINARY,
                ]
            );
        }

        $this->addStateTranslations($connection, $stateId);
        $this->addStateTransitions($connection, $stateMachineId, $stateId);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function addStateTranslations(Connection $connection, string $stateId): void
    {
        $languages = $connection->fetchAllAssociative('SELECT id FROM language');

        foreach ($languages as $language) {
            $connection->executeStatement(
                'INSERT INTO state_machine_state_translation (state_machine_state_id, language_id, name, created_at)
                 VALUES (:stateId, :languageId, :name, :createdAt)
                 ON DUPLICATE KEY UPDATE name = :name',
                [
                    'stateId' => $stateId,
                    'languageId' => $language['id'],
                    'name' => 'Hosted checkout - confirmation',
                    'createdAt' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ],
                [
                    'stateId' => ParameterType::BINARY,
                    'languageId' => ParameterType::BINARY,
                ]
            );
        }
    }

    private function addStateTransitions(Connection $connection, string $stateMachineId, string $stateId): void
    {
        $openStateId = $this->getStateId($connection, $stateMachineId, 'open');
        $inProgressStateId = $this->getStateId($connection, $stateMachineId, 'in_progress');
        $paidStateId = $this->getStateId($connection, $stateMachineId, 'paid');
        $authorizedStateId = $this->getStateId($connection, $stateMachineId, 'authorized');
        $failedStateId = $this->getStateId($connection, $stateMachineId, 'failed');
        $cancelledStateId = $this->getStateId($connection, $stateMachineId, 'cancelled');

        $transitions = [
            [self::ACTION_AWAIT_CONFIRMATION, $openStateId, $stateId],
            [self::ACTION_AWAIT_CONFIRMATION, $inProgressStateId, $stateId],
            [StateMachineTransitionActions::ACTION_PAID, $stateId, $paidStateId],
            [StateMachineTransitionActions::ACTION_AUTHORIZE, $stateId, $authorizedStateId],
            [StateMachineTransitionActions::ACTION_FAIL, $stateId, $failedStateId],
            [StateMachineTransitionActions::ACTION_CANCEL, $stateId, $cancelledStateId],
            [StateMachineTransitionActions::ACTION_REOPEN, $stateId, $openStateId],
        ];

        foreach ($transitions as [$actionName, $fromStateId, $toStateId]) {
            $this->addTransition($connection, $stateMachineId, $actionName, $fromStateId, $toStateId);
        }
    }

    private function addTransition(Connection $connection, string $stateMachineId, string $actionName, ?string $fromStateId, ?string $toStateId): void
    {
        if ($fromStateId === null || $toStateId === null) {
            return;
        }

        $existingTransition = $connection->fetchOne(
            'SELECT id FROM state_machine_transition
             WHERE state_machine_id = :stateMachineId
             AND action_name = :actionName
             AND from_state_id = :fromStateId
             AND to_state_id = :toStateId',
            [
                'stateMachineId' => $stateMachineId,
                'actionName' => $actionName,
                'fromStateId' => $fromStateId,
                'toStateId' => $toStateId,
            ],
            [
                'stateMachineId' => ParameterType::BINARY,
                'fromStateId' => ParameterType::BINARY,
                'toStateId' => ParameterType::BINARY,
            ]
        );

        if ($existingTransition) {
            return;
        }

        $connection->executeStatement(
            'INSERT INTO state_machine_transition
             (id, state_machine_id, action_name, from_state_id, to_state_id, created_at)
             VALUES (:id, :stateMachineId, :actionName, :fromStateId, :toStateId, :createdAt)',
            [
                'id' => Uuid::randomBytes(),
                'stateMachineId' => $stateMachineId,
                'actionName' => $actionName,
                'fromStateId' => $fromStateId,
                'toStateId' => $toStateId,
                'createdAt' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ],
            [
                'id' => ParameterType::BINARY,
                'stateMachineId' => ParameterType::BINARY,
                'fromStateId' => ParameterType::BINARY,
                'toStateId' => ParameterType::BINARY,
            ]
        );
    }

    private function getStateId(Connection $connection, string $stateMachineId, string $technicalName): ?string
    {
        $stateId = $connection->fetchOne(
            'SELECT id FROM state_machine_state
             WHERE state_machine_id = :stateMachineId
             AND technical_name = :technicalName',
            [
                'stateMachineId' => $stateMachineId,
                'technicalName' => $technicalName,
            ],
            [
                'stateMachineId' => ParameterType::BINARY,
            ]
        );

        return $stateId === false ? null : $stateId;
    }
}
