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
  private EntityRepository $orderReturnRepository;
  private StateMachineRegistry $stateMachineRegistry;
  private PositionStateHandler $positionStateHandler;
  private OrderService $orderService;
  private LoggerInterface $logger;

  public function __construct(
    BlueSnapTransactionService   $blueSnapTransactionService,
    BlueSnapApiClient            $blueSnapApiClient,
    EntityRepository             $orderReturnRepository,
    OrderTransactionStateHandler $transactionStateHandler,
    StateMachineRegistry         $stateMachineRegistry,
    PositionStateHandler         $positionStateHandler,
    OrderService                 $orderService,
    LoggerInterface              $logger
  )
  {
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
    $criteria = new Criteria();
    $criteria->addAssociation('order');
    $criteria->addAssociation('lineItems');
    $criteria->addFilter(new EqualsFilter('id', $data['returnId']));
    $orderReturn = $this->orderReturnRepository->search($criteria, $context)->first();
    $orderTransactionId = $this->orderService->getOrderTransactionIdByOrderId($data['orderId'], $context);

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
    $order = $this->orderService->getOrderDetailsById($data['orderId'], $context);

    if ($transaction) {
      $response = $this->blueSnapApiClient->refund($transaction->getTransactionId(), $body, $orderReturn->getOrder()->getSalesChannelID());
      $parsedResponse = json_decode($response, true);
      if ($parsedResponse['refundStatus'] == 'SUCCESS') {

        // TODO: Fix this by calculating every return order
        // -> add every amount and if it matches the order amount
        // -> then is fully returned
        if ($order->getAmountTotal() == $orderReturn->getAmountTotal()) {
          $this->transactionStateHandler->refund($orderTransactionId, $context);
        } else {
          $this->transactionStateHandler->refundPartially($orderTransactionId, $context);
        }

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

}
