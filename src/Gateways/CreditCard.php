<?php

namespace BlueSnap\Gateways;

use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use BlueSnap\Library\Constants\TransactionStatuses;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use BlueSnap\Service\BlueSnapApiClient;
use BlueSnap\Service\BlueSnapConfig;
use BlueSnap\Service\BlueSnapTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use BlueSnap\Service\OrderService;
use BlueSnap\Service\VaultedShopperService;
use Symfony\Component\HttpFoundation\Request;
use \Symfony\Component\HttpFoundation\RedirectResponse;

class CreditCard extends AbstractPaymentHandler
{
  private OrderTransactionStateHandler $transactionStateHandler;
  private BlueSnapTransactionService $blueSnapTransactionService;
  private BlueSnapApiClient $blueSnapApiClient;
  private VaultedShopperService $vaultedShopperService;
  private BlueSnapConfig $blueSnapConfig;
  private OrderService $orderService;
  private LoggerInterface $logger;


  public function __construct(
    OrderTransactionStateHandler $transactionStateHandler,
    BlueSnapTransactionService   $blueSnapTransactionService,
    BlueSnapApiClient            $blueSnapApiClient,
    VaultedShopperService        $vaultedShopperService,
    BlueSnapConfig               $blueSnapConfig,
    OrderService                 $orderService,
    LoggerInterface              $logger
  )
  {
    $this->transactionStateHandler = $transactionStateHandler;
    $this->blueSnapTransactionService = $blueSnapTransactionService;
    $this->blueSnapApiClient = $blueSnapApiClient;
    $this->vaultedShopperService = $vaultedShopperService;
    $this->blueSnapConfig = $blueSnapConfig;
    $this->orderService = $orderService;
    $this->logger = $logger;
  }

  public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
  {
    // This payment handler does not support recurring payments nor refunds
    return false;
  }

  private function orderFirstFlow(Request $request, PaymentTransactionStruct $transaction, OrderTransactionEntity $orderTransaction, string $cardTransactionType, string $handlerMethodName, string $transactionStatus, string $salesChannelId, Context $context): void
  {
    $order = $orderTransaction->getOrder();
    $customer = $order->getOrderCustomer()->getCustomer();

    $isGuestCustomer = $customer->getGuest();
    $currency = $order->getCurrency();
    $billingAddress = $order->getBillingAddress();

    if (!$request->request->get('paymentData')) {
      $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
      throw new \RuntimeException('Missing paymentData');
    }
    $paymentData = json_decode($request->request->get('paymentData'), true);
    if (isset($paymentData['vaultedId'])) {
      $body = [
        "amount" => $orderTransaction->getAmount()->getTotalPrice(),
        "vaultedShopperId" => $paymentData['vaultedId'],
        "softDescriptor" => "Card Capture",
        "currency" => $currency->getIsoCode(),
        "cardTransactionType" => $cardTransactionType,
      ];
    } else {
      $body = [
        "amount" => $orderTransaction->getAmount()->getTotalPrice(),
        "softDescriptor" => "Card Capture",
        "currency" => $currency->getIsoCode(),
        "cardHolderInfo" => [
          "firstName" => $paymentData['firstName'],
          "lastName" => $paymentData['lastName'],
          "zip" => $billingAddress->getZipCode(),
          "country" => strtolower($billingAddress->getCountry()->getIso()),
          "city" => $billingAddress->getCity(),
          "email" => $customer->getEmail(),
        ],
        "pfToken" => $paymentData['pfToken'],
        "cardTransactionType" => $cardTransactionType,
        "transactionInitiator" => "SHOPPER"
      ];
    }

    $is3DSEnabled = $this->blueSnapConfig->getConfig('threeDS', $salesChannelId);
    if ($is3DSEnabled && !empty($data['authResult']) && !empty($data['threeDSecureReferenceId'])) {
      $body['threeDSecure'] = [
        "authResult" => $data['authResult'],
        "threeDSecureReferenceId" => $data['threeDSecureReferenceId']
      ];
    }

    if ($this->blueSnapConfig->Level23DataConfigs($order->getSalesChannelId(), $order->getOrderCustomer()->getCustomer()->getGroupId())) {
      $formatedCartValue = $this->orderService->extractLVL2_3DataFromOrder($order);
      $level3Data = $this->orderService->buildLevel3Data($formatedCartValue, $context);
      if (!empty($level3Data)) {
        $body['level3Data'] = $level3Data;
      }
    }

    $response = $this->blueSnapApiClient->capture($body, $salesChannelId);

    if (isset($response['error'])) {
      $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
      throw new \RuntimeException($response['error']);
    }

    $responseData = json_decode($response, true);
    if ($responseData && $responseData['vaultedShopperId']) {
      $vaultedShopperId = $responseData['vaultedShopperId'];

      if (isset($paymentData['saveCard']) && !$isGuestCustomer) {
        $this->vaultedShopperService->store($vaultedShopperId, $paymentData['cardType'], $customer->getId(), $context);
      }
    }

    $this->transactionStateHandler->{$handlerMethodName}($transaction->getOrderTransactionId(), $context);
    $this->blueSnapTransactionService->addTransaction($order->getId(), $orderTransaction->getPaymentMethod()->getName(), $responseData['transactionId'], $transactionStatus, $context);
  }

  private function paymentFirstFlow(Request $request, PaymentTransactionStruct $transaction, OrderTransactionEntity $orderTransaction, string $handlerMethodName, string $transactionStatus, Context $context): void
  {
    $bluesnapTransactionId = $request->request->get('solu1_bluesnap_transaction_id');
    $orderId = $orderTransaction->getOrder()->getId();
    $this->transactionStateHandler->{$handlerMethodName}($transaction->getOrderTransactionId(), $context);
    $this->blueSnapTransactionService->addTransaction($orderId, $orderTransaction->getPaymentMethod()->getName(), $bluesnapTransactionId, $transactionStatus, $context);
  }

  public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
  {
    $salesChannelId = $request->attributes->get('sw-sales-channel-id');
    $flow = $this->blueSnapConfig->getConfig('flow', $salesChannelId);

    $authorizeOption = $this->blueSnapConfig->getCardTransactionType($salesChannelId);

    $transactionStatus = $authorizeOption == 'AUTH_ONLY' ? TransactionStatuses::AUTHORIZED->value : TransactionStatuses::PAID->value;
    $transactionMethodName = $authorizeOption == 'AUTH_ONLY' ? 'authorize' : 'paid';

    $orderTransaction = $this->orderService->getOrderTransactionsById($transaction->getOrderTransactionId(), $context);
    if (!$orderTransaction) {
      $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
      throw new \RuntimeException('OrderTransaction not found for ID ' . $transaction->getOrderTransactionId());
    }

    if ($flow == 'payment_order') {
      $this->paymentFirstFlow($request, $transaction, $orderTransaction, $transactionMethodName, $transactionStatus, $context);
    } else {
      $this->orderFirstFlow($request, $transaction, $orderTransaction, $authorizeOption, $transactionMethodName, $transactionStatus, $salesChannelId, $context);
    }
    return null;
  }
}
