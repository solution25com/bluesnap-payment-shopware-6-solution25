<?php

namespace BlueSnap\Service;

use BlueSnap\Library\Constants\TransactionStatuses;
use Psr\Log\LoggerInterface;
use Shopware\Commercial\ReturnManagement\Domain\StateHandler\PositionStateHandler;
use Shopware\Commercial\ReturnManagement\Entity\OrderReturn\OrderReturnDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

class RefundService
{
    private BlueSnapTransactionService $blueSnapTransactionService;
    private BlueSnapApiClient $blueSnapApiClient;
    private OrderTransactionStateHandler $transactionStateHandler;
    private ?EntityRepository $orderReturnRepository;
    private StateMachineRegistry $stateMachineRegistry;
    private ?PositionStateHandler $positionStateHandler;
    private OrderService $orderService;
    private LoggerInterface $logger;

    public function __construct(
        BlueSnapTransactionService $blueSnapTransactionService,
        BlueSnapApiClient $blueSnapApiClient,
        ?EntityRepository $orderReturnRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        StateMachineRegistry $stateMachineRegistry,
        ?PositionStateHandler $positionStateHandler,
        OrderService $orderService,
        LoggerInterface $logger
    ) {
        $this->blueSnapTransactionService = $blueSnapTransactionService;
        $this->blueSnapApiClient = $blueSnapApiClient;
        $this->orderReturnRepository = $orderReturnRepository;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->positionStateHandler = $positionStateHandler;
        $this->orderService = $orderService;
        $this->logger = $logger;
    }

    public function handelRefunds($data, Context $context)
    {
        if ($this->orderReturnRepository === null) {
            $this->logger->error('OrderReturnRepository is not available');
            return null;
        }


        $criteria = new Criteria();
        $criteria->addAssociation('order');
        $criteria->addAssociation('lineItems');
        $criteria->addFilter(new EqualsFilter('id', $data['returnId']));
        $orderReturn = $this->orderReturnRepository->search($criteria, $context)->first();
        $orderTransactionId = $this->orderService->getOrderTransactionIdByOrderId($data['orderId'], $context);

        if (!$orderReturn) {
            $this->logger->error('$orderReturn is not available');
            return null;
        }
        if (!$orderTransactionId) {
            $this->logger->error('$orderTransactionId is not available');
            return null;
        }

        try {
            $this->stateMachineRegistry->transition(
                new Transition(
                    OrderReturnDefinition::ENTITY_NAME,
                    $orderReturn->getId(),
                    StateMachineTransitionActions::ACTION_PROCESS,
                    'stateId'
                ),
                $context
            );
        } catch (\Exception $exception) {
            $this->logger->error('Error while changing status to InProgress');
            $this->logger->error($exception->getMessage());
        }

        $body = [
            "cancelSubscriptions" => false,
            'amount' => $orderReturn->getAmountTotal(),
        ];

        $transaction = $this->blueSnapTransactionService->getTransactionByOrderId($data['orderId'], $context);
        $order = $this->orderService->getOrderDetailsById($data['orderId'], $context);

        if ($transaction) {
            $response = $this->blueSnapApiClient->refund($transaction->getTransactionId(), $body, $orderReturn->getOrder()->getSalesChannelID());
            $parsedResponse = json_decode($response, true);

            if ($parsedResponse['refundStatus'] == 'SUCCESS') {
                // TODO: Fix this by calculating every return order
                // -> add every amount and if it matches the order amount
                // -> then is fully returned
                try {
                    if ($order->getAmountTotal() == $orderReturn->getAmountTotal()) {
                        $this->transactionStateHandler->refund($orderTransactionId, $context);
                    } else {
                        $this->transactionStateHandler->refundPartially($orderTransactionId, $context);
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Error while changing order status');
                    $this->logger->error($e->getMessage());
                }

                try {
                    $this->blueSnapTransactionService->updateTransactionStatus(
                        $data['orderId'],
                        TransactionStatuses::REFUND->value,
                        $context
                    );
                } catch (\Exception $e) {
                    $this->logger->error('Error while changing transaction status');
                    $this->logger->error($e->getMessage());
                }


                try {
                    $this->stateMachineRegistry->transition(
                        new Transition(
                            OrderReturnDefinition::ENTITY_NAME,
                            $orderReturn->getId(),
                            StateMachineTransitionActions::ACTION_COMPLETE,
                            'stateId'
                        ),
                        $context
                    );
                } catch (\Exception $e) {
                    $this->logger->error('Error while changing status to Complete');
                    $this->logger->error($e->getMessage());
                }

                try {
                    $itemIds = [];
                    foreach ($orderReturn->getLineItems() as $lineItem) {
                        $itemIds[] = $lineItem->getId();
                    }
                    $this->positionStateHandler->transitReturnItems($itemIds, PositionStateHandler::STATE_RETURNED, $context);
                } catch (\Exception $e) {
                    $this->logger->error('Error while changing return item status');
                    $this->logger->error($e->getMessage());
                }

                // TODO: if order line item status needs to be updated to partial return
                // and if complete quantity eq true then mark as full return -> function to be used:
            }
            return $parsedResponse;
        }
        return null;
    }
}
