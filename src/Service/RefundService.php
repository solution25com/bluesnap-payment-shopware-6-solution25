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
    BlueSnapTransactionService   $blueSnapTransactionService,
    BlueSnapApiClient            $blueSnapApiClient,
    ?EntityRepository            $orderReturnRepository,
    OrderTransactionStateHandler $transactionStateHandler,
    StateMachineRegistry         $stateMachineRegistry,
    ?PositionStateHandler         $positionStateHandler,
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
    if ($this->orderReturnRepository === null) {
      $this->logger->error('OrderReturnRepository is not available');
      $parsedResponse['message'] = 'OrderReturnRepository is not available';
      return $parsedResponse;
    }

    $criteria = new Criteria();
    $criteria->addAssociation('order');
    $criteria->addAssociation('lineItems');
    $criteria->addFilter(new EqualsFilter('id', $data['returnId']));
    $orderReturn = $this->orderReturnRepository->search($criteria, $context)->first();
    $orderTransactionId = $this->orderService->getOrderTransactionIdByOrderId($data['orderId'], $context);

    if(!$orderReturn){
      $this->logger->error('$orderReturn is not available');
      return null;
    }
    if(!$orderTransactionId){
      $this->logger->error('$orderTransactionId is not available');
      return null;
    }

    try{
      $this->stateMachineRegistry->transition(
        new Transition(
          OrderReturnDefinition::ENTITY_NAME,
          $orderReturn->getId(),
          StateMachineTransitionActions::ACTION_PROCESS,
          'stateId'
        ),
        $context
      );
    }
    catch (\Exception $exception){
      $this->logger->error('Error while changing status to InProgress');
      $this->logger->error($exception->getMessage());
    }

    $body = [
      "cancelSubscriptions" => false,
      'amount' => $orderReturn->getAmountTotal(),
    ];

    $transaction = $this->blueSnapTransactionService->getTransactionByOrderId($data['orderId'], $context);
    $order = $this->orderService->getOrderDetailsById($data['orderId'], $context);

      if (!$transaction) {
        $this->logger->error('Transaction not found for this order');
        $parsedResponse['message'] = 'Transaction not found';
        return $parsedResponse;
      }

      $response = $this->blueSnapApiClient->refund($transaction->getTransactionId(), $body, $orderReturn->getOrder()->getSalesChannelID());
      $parsedResponse = is_string($response) ? json_decode($response, true) : $response;

      if (!empty($parsedResponse['error'])) {
        $this->logger->error('BlueSnap refund failed', $parsedResponse);
        return $parsedResponse;
      }

      $criteria = new Criteria();
      $criteria->addFilter(new EqualsFilter('orderId', $data['orderId']));
      $allReturns = $this->orderReturnRepository->search($criteria, $context);

      $totalRefundedAmount = 0;
      foreach ($allReturns->getElements() as $return) {
        $totalRefundedAmount += (int) round($return->getAmountTotal() * 100);
      }

      $orderTotalCents = (int) round($order->getAmountTotal() * 100);

      if (($parsedResponse['refundStatus'] ?? null) === 'SUCCESS') {
        try{
          if ($orderTotalCents == $totalRefundedAmount) {
            $this->transactionStateHandler->refund($orderTransactionId, $context);
          } else {
            $this->transactionStateHandler->refundPartially($orderTransactionId, $context);
          }
        }
        catch (\Exception $e){
          $this->logger->error('Error while changing order status');
          $this->logger->error($e->getMessage());
        }

        try {
          $this->blueSnapTransactionService->updateTransactionStatus(
            $data['orderId'],
            TransactionStatuses::REFUND->value,
            $context
          );
        }
        catch (\Exception $e){
          $this->logger->error('Error while changing transaction status');
          $this->logger->error($e->getMessage());
        }

        try{
          $this->stateMachineRegistry->transition(
            new Transition(
              OrderReturnDefinition::ENTITY_NAME,
              $orderReturn->getId(),
              StateMachineTransitionActions::ACTION_COMPLETE,
              'stateId'
            ),
            $context
          );
        }
        catch (\Exception $e){
          $this->logger->error('Error while changing status to Complete');
          $this->logger->error($e->getMessage());
        }

        try{
          $itemIds = [];
          foreach ($orderReturn->getLineItems() as $lineItem) {
            $itemIds[] = $lineItem->getId();
          }
          $this->positionStateHandler->transitReturnItems($itemIds, PositionStateHandler::STATE_RETURNED, $context);
        }
        catch (\Exception $e){
          $this->logger->error('Error while changing return item status');
          $this->logger->error($e->getMessage());
        }
      }
      return $parsedResponse;
    }
}
