<?php

namespace BlueSnap\Service;

use BlueSnap\Library\Constants\TransactionStatuses;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class RefundService
{
    private BlueSnapTransactionService $blueSnapTransactionService;

    private BlueSnapApiClient $blueSnapApiClient;
    private EntityRepository $orderTransactionRepository;
    private OrderTransactionStateHandler $transactionStateHandler;
    private EntityRepository $orderReturnRepository;
    private LoggerInterface $logger;

    public function __construct(
        BlueSnapTransactionService $blueSnapTransactionService,
        BlueSnapApiClient $blueSnapApiClient,
        EntityRepository $orderReturnRepository,
        EntityRepository $orderTransactionRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        LoggerInterface $logger
    ) {
        $this->blueSnapTransactionService = $blueSnapTransactionService;
        $this->blueSnapApiClient          = $blueSnapApiClient;
        $this->orderReturnRepository      = $orderReturnRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->transactionStateHandler    = $transactionStateHandler;
        $this->logger                     = $logger;
    }


    public function handelRefunds($data, Context $context)
    {

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $data['returnId']));
        $orderReturn        = $this->orderReturnRepository->search($criteria, $context)->first();
        $orderTransactionId = $this->getOrderTransactionIdByOrderId($data['orderId'], $context);

        $body = [
          "cancelSubscriptions" => false,
          'amount' => $orderReturn->getAmountTotal(),
        ];

        $transaction = $this->blueSnapTransactionService->getTransactionByOrderId($data['orderId'], $context);

        if ($transaction) {
            $response       = $this->blueSnapApiClient->refund($transaction->getTransactionId(), $body);
            $parsedResponse = json_decode($response, true);
            if ($parsedResponse['refundStatus'] == 'SUCCESS') {
                $this->transactionStateHandler->refundPartially($orderTransactionId, $context);
                $this->blueSnapTransactionService->updateTransactionStatus(
                    $data['orderId'],
                    TransactionStatuses::REFUND->value,
                    $context
                );
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
