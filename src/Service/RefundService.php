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
  private EntityRepository $orderTransactionRepository;
  private OrderTransactionStateHandler $transactionStateHandler;
  private EntityRepository $orderReturnRepository;
  private StateMachineRegistry $stateMachineRegistry;
  private PositionStateHandler $positionStateHandler;
  private LoggerInterface $logger;

  public function __construct(
    BlueSnapTransactionService   $blueSnapTransactionService,
    BlueSnapApiClient            $blueSnapApiClient,
    EntityRepository             $orderReturnRepository,
    EntityRepository             $orderTransactionRepository,
    OrderTransactionStateHandler $transactionStateHandler,
    StateMachineRegistry         $stateMachineRegistry,
    PositionStateHandler         $positionStateHandler,
    LoggerInterface              $logger
  )
  {
    $this->blueSnapTransactionService = $blueSnapTransactionService;
    $this->blueSnapApiClient = $blueSnapApiClient;
    $this->orderReturnRepository = $orderReturnRepository;
    $this->orderTransactionRepository = $orderTransactionRepository;
    $this->transactionStateHandler = $transactionStateHandler;
    $this->stateMachineRegistry = $stateMachineRegistry;
    $this->positionStateHandler = $positionStateHandler;
    $this->logger = $logger;
  }


  public function handelRefunds($data, Context $context)
  {
    $criteria = new Criteria();
    $criteria->addAssociation('order');
    $criteria->addAssociation('lineItems');
    $criteria->addFilter(new EqualsFilter('id', $data['returnId']));
    $orderReturn = $this->orderReturnRepository->search($criteria, $context)->first();
    $orderTransactionId = $this->getOrderTransactionIdByOrderId($data['orderId'], $context);

    $this->stateMachineRegistry->transition(
      new Transition(
        OrderReturnDefinition::ENTITY_NAME,
        $orderReturn->getId(),
        StateMachineTransitionActions::ACTION_PROCESS,
        'stateId'
      ),
      $context
    );

    $body = [
      "cancelSubscriptions" => false,
      'amount' => $orderReturn->getAmountTotal(),
    ];

    $transaction = $this->blueSnapTransactionService->getTransactionByOrderId($data['orderId'], $context);

    if ($transaction) {
      $response = $this->blueSnapApiClient->refund($transaction->getTransactionId(), $body, $orderReturn->getOrder()->getSalesChannelID());
      $parsedResponse = json_decode($response, true);
      if ($parsedResponse['refundStatus'] == 'SUCCESS') {

        // TODO: Check if complete order has been refunded
        $this->transactionStateHandler->refundPartially($orderTransactionId, $context);

        $this->blueSnapTransactionService->updateTransactionStatus(
          $data['orderId'],
          TransactionStatuses::REFUND->value,
          $context
        );

        $this->stateMachineRegistry->transition(
          new Transition(
            OrderReturnDefinition::ENTITY_NAME,
            $orderReturn->getId(),
            StateMachineTransitionActions::ACTION_COMPLETE,
            'stateId'
          ),
          $context
        );

        $itemIds = [];
        foreach ($orderReturn->getLineItems() as $lineItem) {
          $itemIds[] = $lineItem->getId();
        }
        $this->positionStateHandler->transitReturnItems($itemIds, PositionStateHandler::STATE_RETURNED, $context);

        // TODO: if order line item status needs to be updated to partial return
        // and if complete quantity eq true then mark as full return -> function to be used:
        // $this->positionStateHandler->transitOrderLineItems();
      }
      return $parsedResponse;
    }
    return null;
  }

  private function getOrderTransactionIdByOrderId($orderId, $context)
  {
    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('orderId', $orderId));
    $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();
    if ($orderTransaction) {
      return $orderTransaction->getId();
    }
    return null;
  }
}
