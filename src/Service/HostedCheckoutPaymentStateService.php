<?php

declare(strict_types=1);

namespace BlueSnap\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

class HostedCheckoutPaymentStateService
{
    public const STATE_AWAITING_CONFIRMATION = 'bluesnap_awaiting_confirmation';

    private const ACTION_AWAIT_CONFIRMATION = 'bluesnap_await_confirmation';

    public function __construct(
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function hold(string $orderTransactionId, Context $context): void
    {
        if ($this->getPaymentState($orderTransactionId, $context) === self::STATE_AWAITING_CONFIRMATION) {
            return;
        }

        try {
            $this->stateMachineRegistry->transition(
                new Transition(
                    'order_transaction',
                    $orderTransactionId,
                    self::ACTION_AWAIT_CONFIRMATION,
                    'stateId',
                    'Waiting for BlueSnap hosted checkout webhook confirmation'
                ),
                $context
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Could not put BlueSnap hosted checkout payment on hold', [
                'orderTransactionId' => $orderTransactionId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function getPaymentState(string $orderTransactionId, Context $context): ?string
    {
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('stateMachineState');

        /** @var OrderTransactionEntity|null $transaction */
        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        return $transaction?->getStateMachineState()?->getTechnicalName();
    }
}
