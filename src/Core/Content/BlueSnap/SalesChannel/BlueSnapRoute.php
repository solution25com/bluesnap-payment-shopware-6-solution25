<?php

namespace BlueSnap\Core\Content\BlueSnap\SalesChannel;

use BlueSnap\PaymentMethods\PaymentMethods;
use Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Payment\SalesChannel\HandlePaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\HandlePaymentMethodRouteResponse;
use BlueSnap\Core\Content\BlueSnap\AbstractBlueSnapRoute;
use BlueSnap\Core\Content\BlueSnap\BlueSnapApiResponseStruct;
use BlueSnap\Library\Constants\TransactionStatuses;
use BlueSnap\Library\ValidatorUtility;
use BlueSnap\Service\BlueSnapApiClient;
use BlueSnap\Service\BlueSnapConfig;
use BlueSnap\Service\BlueSnapTransactionService;
use BlueSnap\Service\OrderService;
use BlueSnap\Service\PaymentLinkService;
use BlueSnap\Service\RefundService;
use BlueSnap\Service\VaultedShopperService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

#[Route(defaults: ["_routeScope" => ['store-api'], "_loginRequired" => true, "_loginRequiredAllowGuest" => true])]
class BlueSnapRoute extends AbstractBlueSnapRoute
{
  private BlueSnapApiClient $blueSnapClient;
  private BlueSnapConfig $blueSnapConfig;
  private ValidatorUtility $validator;
  private VaultedShopperService $vaultedShopperService;
  private OrderService $orderService;
  private PaymentLinkService $paymentLinkService;
  private BlueSnapTransactionService $blueSnapTransactionService;
  private RefundService $refundService;
  private OrderTransactionStateHandler $transactionStateHandler;
  private HandlePaymentMethodRoute $handlePaymentMethodRoute;
  private CartOrderRoute $cartOrderRoute;
  private CartService $cartService;
  private LoggerInterface $logger;


  public function __construct(
    BlueSnapApiClient            $client,
    BlueSnapConfig               $blueSnapConfig,
    ValidatorUtility             $validator,
    VaultedShopperService        $vaultedShopperService,
    OrderService                 $orderService,
    PaymentLinkService           $paymentLinkService,
    BlueSnapTransactionService   $blueSnapTransactionService,
    RefundService                $refundService,
    OrderTransactionStateHandler $transactionStateHandler,
    HandlePaymentMethodRoute     $handlePaymentMethodRoute,
    CartOrderRoute               $cartOrderRoute,
    CartService                  $cartService,
    LoggerInterface              $logger,
  )
  {
    $this->blueSnapClient = $client;
    $this->blueSnapConfig = $blueSnapConfig;
    $this->validator = $validator;
    $this->vaultedShopperService = $vaultedShopperService;
    $this->orderService = $orderService;
    $this->paymentLinkService = $paymentLinkService;
    $this->blueSnapTransactionService = $blueSnapTransactionService;
    $this->refundService = $refundService;
    $this->transactionStateHandler = $transactionStateHandler;
    $this->handlePaymentMethodRoute = $handlePaymentMethodRoute;
    $this->cartOrderRoute = $cartOrderRoute;
    $this->cartService = $cartService;
    $this->logger = $logger;
  }

  public function getDecorated(): AbstractBlueSnapRoute
  {
    throw new DecorationPatternException(self::class);
  }

  #[Route(path: '/store-api/bluesnap/get-pf-token', name: 'store-api.bluesnap.getPfToken', methods: ['GET'])]
  public function getPfToken(Request $request, SalesChannelContext $context): BlueSnapApiResponse
  {
    $response = $this->blueSnapClient->makeTokenRequest([], $context->getSalesChannelId());
    return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $response));
  }

  #[Route(path: '/store-api/bluesnap/refund', name: 'store-api.bluesnap.refund', methods: ['POST'])]
  public function refund(Request $request, Context $context): BlueSnapApiResponse
  {

    $data = $request->request->all();
    $constraints = new Assert\Collection([
      'orderId' => [new Assert\NotBlank(), new Assert\Type('string')],
      'returnId' => [new Assert\NotBlank(), new Assert\Type('string')],
    ]);

    $errors = $this->validator->validateFields($data, $constraints);
    if (count($errors) > 0) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
    }

    $response = $this->refundService->handelRefunds($data, $context);

    if($response['error']){
      $this->logger->error('Refund error: ' . json_encode($response));
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $response['message']), $response['code']);
    }

    return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $response));
  }

  #[Route(path: '/store-api/bluesnap/capture', name: 'store-api.bluesnap.capture', methods: ['POST'])]
  public function capture(Request $request, SalesChannelContext $context): BlueSnapApiResponse
  {

    $cart = $this->cartService->getCart($context->getToken(), $context);

    $errors = $cart->getErrors();
    $outOfStockError = false;

    foreach ($errors as $error) {
      if($error) {
        $outOfStockError = true;
        break;
      }
    }

    if($outOfStockError) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
    }

    $salesChannelId = $context->getSalesChannel()->getId();
    $is3DSEnabled = $this->blueSnapConfig->getConfig('threeDS', $salesChannelId);
    $data = $request->request->all();

    $cardTransactionType = $this->blueSnapConfig->getCardTransactionType($salesChannelId);

    $customer = $context->getCustomer();
    $billingAddress = $customer->getActiveBillingAddress() ?? $customer->getDefaultBillingAddress();
    $city = $billingAddress->getCity();
    $zipCode = $billingAddress->getZipCode();
    $country = $billingAddress->getCountry()->getIso();
    $email = $customer->getEmail();

    $constraints = new Assert\Collection([
      'pfToken' => [new Assert\NotBlank(), new Assert\Type('string')],
      'firstName' => [new Assert\NotBlank(), new Assert\Type('string')],
      'lastName' => [new Assert\NotBlank(), new Assert\Type('string')],
      'amount' => [new Assert\NotBlank(), new Assert\Type('string')],
      'saveCard' => [
        new Assert\Optional([
          new Assert\Type('bool'),
        ])
      ],
      'cardType' => [new Assert\NotBlank(), new Assert\Type('string')],
      'authResult' => [
        new Assert\Optional([
          new Assert\Type('string'),
        ])
      ],
      'threeDSecureReferenceId' => [
        new Assert\Optional([
          new Assert\Type('string'),
        ])
      ],
      'cartData' => new Assert\Optional([
        new Assert\Type('array'),
      ]),
    ]);

    $errors = $this->validator->validateFields($data, $constraints);
    if (count($errors) > 0) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
    }

    $body = [
      "amount" => round((float)$data['amount'], 2),
      "softDescriptor" => "Card Capture",
      "currency" => $context->getCurrency()->getIsoCode(),
      "cardHolderInfo" => [
        "firstName" => $data['firstName'],
        "lastName" => $data['lastName'],
        "zip" => $zipCode,
        "country" => strtolower($country),
        "city" => $city,
        "email" => $email

      ],
      "pfToken" => $data['pfToken'],
      "cardTransactionType" => $cardTransactionType,
      "transactionInitiator" => "SHOPPER"
    ];


    if ($is3DSEnabled && !empty($data['authResult']) && !empty($data['threeDSecureReferenceId'])) {
      $body['threeDSecure'] = [
        "authResult" => $data['authResult'],
        "threeDSecureReferenceId" => $data['threeDSecureReferenceId']
      ];
    }

    if (!empty($data['cartData'])) {
      $level3Data = $this->orderService->buildLevel3Data($data['cartData'], $context->getContext());
      if (!empty($level3Data)) {
        $body['level3Data'] = $level3Data;
      }
    }

    $response = $this->blueSnapClient->capture($body, $salesChannelId);

    if (isset($response['error'])) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $response['message']), $response['code']);
    }

    $responseData = json_decode($response, true);
    if ($responseData && $responseData['vaultedShopperId']) {
      $vaultedShopperId = $responseData['vaultedShopperId'];
      $isGuestCustomer = $context->getCustomer()->getGuest();
      if ($data['saveCard'] && !$isGuestCustomer) {
        $this->vaultedShopperService->store($vaultedShopperId, $data['cardType'], $context->getCustomer()->getId(), $context->getContext());
      }
    }

    $orderResponse = $this->cartOrderRoute->order($cart, $context, new RequestDataBag());

    $order = $orderResponse->getOrder();
    $orderTransaction = $order->getTransactions()->first();

    $this->blueSnapTransactionService->addTransaction(
      $order->getId(),
      $orderTransaction->getPaymentMethod()->getName(),
      $responseData['transactionId'],
      $cardTransactionType == 'AUTH_ONLY' ? TransactionStatuses::AUTHORIZED->value : TransactionStatuses::PAID->value,
      $context->getContext()
    );

    $statusHandlerFunctionName = $cardTransactionType == 'AUTH_ONLY' ? 'authorize' : 'paid';
    $this->transactionStateHandler->{$statusHandlerFunctionName}($orderTransaction->getId(), $context->getContext());

    return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, ["orderId" => $order->getId()]));
  }

  #[Route(path: '/store-api/bluesnap/google-capture', name: 'store-api.bluesnap.googleCapture', methods: ['POST'])]
  public function googleCapture(Request $request, SalesChannelContext $context): BlueSnapApiResponse
  {

    $cart = $this->cartService->getCart($context->getToken(), $context);

    $errors = $cart->getErrors();
    $outOfStockError = false;

    foreach ($errors as $error) {
      if($error) {
        $outOfStockError = true;
        break;
      }
    }

    if($outOfStockError) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
    }

    $data = $request->request->all();
    $cardTransactionType = $this->blueSnapConfig->getCardTransactionType($context->getSalesChannelId());
    $constraints = new Assert\Collection([
      'gToken' => [new Assert\NotBlank(), new Assert\Type('string')],
      'amount' => [new Assert\NotBlank(), new Assert\Type('string')],
      'email' => [new Assert\NotBlank(), new Assert\Type('string')],
      'cartData' => new Assert\Optional([
        new Assert\Type('array'),
      ]),
    ]);

    $errors = $this->validator->validateFields($data, $constraints);

    if (count($errors) > 0) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
    }

    $body = [
      "amount" => round((float)$data['amount'], 2),
      "softDescriptor" => "Google Pay",
      "currency" => $context->getCurrency()->getIsoCode(),
      "cardTransactionType" => $cardTransactionType,
      "wallet" => [
        "walletType" => "GOOGLE_PAY",
        "encodedPaymentToken" => $data['gToken'],
      ],
      "cardHolderInfo" => [
        "email" => $data['email'],
      ]
    ];

    $salesChannelId = $context->getSalesChannel()->getId();

    if (!empty($data['cartData'])) {
      $level3Data = $this->orderService->buildLevel3Data($data['cartData'], $context->getContext());
      if (!empty($level3Data)) {
        $body['level3Data'] = $level3Data;
      }
    }
    $response = $this->blueSnapClient->capture($body, $salesChannelId);

    if (isset($response['error'])) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $response['message']), $response['code']);
    }

    $responseDecoded = json_decode($response, true);

    $orderResponse = $this->cartOrderRoute->order($cart, $context, new RequestDataBag());

    $order = $orderResponse->getOrder();
    $orderTransaction = $order->getTransactions()->first();

    $this->blueSnapTransactionService->addTransaction(
      $order->getId(),
      $orderTransaction->getPaymentMethod()->getName(),
      $responseDecoded['transactionId'],
      $cardTransactionType == 'AUTH_ONLY' ? TransactionStatuses::AUTHORIZED->value : TransactionStatuses::PAID->value,
      $context->getContext()
    );

    $statusHandlerFunctionName = $cardTransactionType == 'AUTH_ONLY' ? 'authorize' : 'paid';
    $this->transactionStateHandler->{$statusHandlerFunctionName}($orderTransaction->getId(), $context->getContext());

    return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, ['orderId' => $order->getId()]));
  }

  #[Route(path: '/store-api/bluesnap/apple-capture', name: 'store-api.bluesnap.appleCapture', methods: ['POST'])]
  public function appleCapture(Request $request, SalesChannelContext $context): BlueSnapApiResponse
  {

    $cart = $this->cartService->getCart($context->getToken(), $context);

    $errors = $cart->getErrors();
    $outOfStockError = false;

    foreach ($errors as $error) {
      if($error) {
        $outOfStockError = true;
        break;
      }
    }

    if($outOfStockError) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
    }

    $data = $request->request->all();
    $cardTransactionType = $this->blueSnapConfig->getCardTransactionType($context->getSalesChannelId());

    $customerEmail = $context->getCustomer()->getEmail();
    $constraints = new Assert\Collection([
      'appleToken' => [new Assert\NotBlank(), new Assert\Type('string')],
      'amount' => [new Assert\NotBlank(), new Assert\Type('string')],
      'cartData' => new Assert\Optional([
        new Assert\Type('array'),
      ]),
      'email' => new Assert\Optional([
        new Assert\Type('string'),
      ]),
    ]);

    $errors = $this->validator->validateFields($data, $constraints);
    if (count($errors) > 0) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
    }
    $body = [
      "amount" => round((float)$data['amount'], 2),
      "softDescriptor" => "Apple Pay",
      "currency" => $context->getCurrency()->getIsoCode(),
      "cardTransactionType" => "$cardTransactionType",
      "wallet" => [
        "walletType" => "APPLE_PAY",
        "encodedPaymentToken" => $data['appleToken'],
      ],
      "cardHolderInfo" => [
        "email" => $customerEmail
      ]];


    if (!empty($data['cartData'])) {
      $level3Data = $this->orderService->buildLevel3Data($data['cartData'], $context->getContext());
      if (!empty($level3Data)) {
        $body['level3Data'] = $level3Data;
      }
    }

    $response = $this->blueSnapClient->capture($body, $context->getSalesChannelId());
    if (isset($response['error'])) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $response['message']), $response['code']);
    }

    $responseDecoded = json_decode($response, true);

    $orderResponse = $this->cartOrderRoute->order($cart, $context, new RequestDataBag());

    $order = $orderResponse->getOrder();
    $orderTransaction = $order->getTransactions()->first();
    $this->blueSnapTransactionService->addTransaction(
      $order->getId(),
      $orderTransaction->getPaymentMethod()->getName(),
      $responseDecoded['transactionId'],
      $cardTransactionType == 'AUTH_ONLY' ? TransactionStatuses::AUTHORIZED->value : TransactionStatuses::PAID->value,
      $context->getContext()
    );

    $statusHandlerFunctionName = $cardTransactionType == 'AUTH_ONLY' ? 'authorize' : 'paid';
    $this->transactionStateHandler->{$statusHandlerFunctionName}($orderTransaction->getId(), $context->getContext());

    return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, ['orderId' => $order->getId()]));
  }

  #[Route(path: '/store-api/bluesnap/apple-create-wallet', name: 'store-api.bluesnap.appleCreateWallet', methods: ['POST'])]
  public function appleCreateWallet(Request $request, SalesChannelContext $context): BlueSnapApiResponse
  {
    $data = $request->request->all();
    $constraints = new Assert\Collection([
      'validationUrl' => [new Assert\NotBlank(), new Assert\Type('string')],
      'domainName' => [new Assert\NotBlank(), new Assert\Type('string')],
      'displayName' => [new Assert\NotBlank(), new Assert\Type('string')],
    ]);

    $errors = $this->validator->validateFields($data, $constraints);
    if (count($errors) > 0) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
    }

    $response = $this->blueSnapClient->appleWalletRequest([
      "walletType" => "APPLE_PAY",
      "validationUrl" => $data["validationUrl"],
      "domainName" => $data["domainName"],
      "displayName" => $data["displayName"],
    ], $context->getSalesChannelId());
    if (isset($response['error'])) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $response['message']), $response['code']);
    }
    $responseBody = trim($response);
    $responseBody = preg_replace('/^\xEF\xBB\xBF/', '', $responseBody);
    $decodedData = json_decode($responseBody, true);
    $base64Decoded = base64_decode($decodedData['walletToken']);

    return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $base64Decoded));
  }

  #[Route(path: '/store-api/bluesnap/get-config', name: 'store-api.bluesnap.getBluesnapConfig', defaults: ["_loginRequired" => false], methods: ['GET'])]
  public function getBluesnapConfig(Request $request, SalesChannelContext $context): BlueSnapApiResponse
  {
    $salesChannelId = $context->getSalesChannel()->getId();
    $config = [
      'mode' => $this->blueSnapConfig->getConfig('mode', $salesChannelId),
      'merchantId' => $this->blueSnapConfig->getConfig('merchantId', $salesChannelId),
      '3D' => $this->blueSnapConfig->getConfig('threeDS', $salesChannelId) ?? false,
      'merchantGoogleId' => $this->blueSnapConfig->getConfig('merchantGoogleId', $salesChannelId),
    ];
    return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $config));
  }

  #[Route(path: '/store-api/bluesnap/vaulted-shopper', name: 'store-api.bluesnap.vaultedShopper', methods: ['POST'])]
  public function vaultedShopper(Request $request, SalesChannelContext $context): BlueSnapApiResponse
  {
    $salesChannelId = $context->getSalesChannel()->getId();
    $cardTransactionType = $this->blueSnapConfig->getCardTransactionType($context->getSalesChannelId());


    $is3DSEnabled = $this->blueSnapConfig->getConfig('threeDS', $salesChannelId);
    $data = $request->request->all();
    $constraints = new Assert\Collection([
      'pfToken' => [new Assert\NotBlank(), new Assert\Type('string')],
      'vaultedId' => [new Assert\NotBlank(), new Assert\Type('string')],
      'amount' => [new Assert\NotBlank(), new Assert\Type('string')],
      'authResult' => [
        new Assert\Optional([
          new Assert\Type('string'),
        ])
      ],
      'threeDSecureReferenceId' => [
        new Assert\Optional([
          new Assert\Type('string'),
        ])
      ],
      'cartData' => new Assert\Optional([
        new Assert\Type('array'),
      ]),
    ]);

    $errors = $this->validator->validateFields($data, $constraints);
    if (count($errors) > 0) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
    }

    $vaultedId = $data['vaultedId'];

    $body = [
      "amount" => round((float)$data['amount'], 2),
      "vaultedShopperId" => $vaultedId,
      "softDescriptor" => "DescTest",
      "currency" => $context->getCurrency()->getIsoCode(),
      "cardTransactionType" => $cardTransactionType,
    ];

    if ($is3DSEnabled && !empty($data['authResult']) && !empty($data['threeDSecureReferenceId'])) {
      $body['threeDSecure'] = [
        "authResult" => $data['authResult'],
        "threeDSecureReferenceId" => $data['threeDSecureReferenceId']
      ];
    }

    if (!empty($data['cartData'])) {
      $level3Data = $this->orderService->buildLevel3Data($data['cartData'], $context->getContext());
      if (!empty($level3Data)) {
        $body['level3Data'] = $level3Data;
      }
    }


    $response = $this->blueSnapClient->capture($body, $context->getSalesChannelId());
    if (isset($response['error'])) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $response['message']), $response['code']);
    }
    return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $response));
  }

  #[Route(path: '/store-api/bluesnap/vaulted-shopper-data/{vaultedShopperId}', name: 'store-api.bluesnap.vaultedShopperData', methods: ['GET'])]
  public function vaultedShopperData(string $vaultedShopperId, Request $request, SalesChannelContext $context): BlueSnapApiResponse
  {
    $vaultedShopperData = $this->blueSnapClient->getVaultedShopper($vaultedShopperId, $context->getSalesChannelId());
    if (isset($vaultedShopperData['error'])) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $vaultedShopperData['message']), $vaultedShopperData['code']);
    }
    if (!$vaultedShopperData) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, "error fetching shopper data"), 400);
    }
    return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $vaultedShopperData));
  }

  #[Route(path: '/store-api/bluesnap/update-vaulted-shopper/{vaultedShopperId}', name: 'store-api.bluesnap.updateVaultedShopper', methods: ['PUT'])]
  public function updateVaultedShopper(string $vaultedShopperId, Request $request, SalesChannelContext $context): BlueSnapApiResponse
  {
    $data = $request->request->all();
    $constraints = new Assert\Collection([
      'pfToken' => [new Assert\NotBlank(), new Assert\Type('string')],
      'firstName' => [new Assert\NotBlank(), new Assert\Type('string')],
      'lastName' => [new Assert\NotBlank(), new Assert\Type('string')],
      'cardType' => [new Assert\NotBlank(), new Assert\Type('string')],
      'cardLastFourDigits' => [new Assert\NotBlank(), new Assert\Type('string')],
    ]);

    $errors = $this->validator->validateFields($data, $constraints);
    if (count($errors) > 0) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
    }

    $body = [
      'firstName' => $data['firstName'],
      'lastName' => $data['lastName'],
      'paymentSources' => [
        'creditCardInfo' => [
          [
            'creditCard' => [
              'cardType' => $data['cardType'],
              'cardLastFourDigits' => $data['cardLastFourDigits'],
            ],
            'status' => 'D',
          ]
        ]
      ]
    ];

    $vaultedShopperData = $this->blueSnapClient->updateVaultedShopper($vaultedShopperId, $body, $context->getSalesChannelId());
    if (isset($vaultedShopperData['error'])) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $vaultedShopperData['message']), $vaultedShopperData['code']);
    }

    return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $vaultedShopperData));
  }

  #[Route(path: '/store-api/bluesnap/hosted-pages-link', name: 'store-api.bluesnap.hostedPagesLink', methods: ['POST'])]
  public function hostedPagesLink(Request $request, SalesChannelContext $context): BlueSnapApiResponse
  {
    $data = $request->request->all();

    $constraints = new Assert\Collection([
      'order_id' => [new Assert\NotBlank(), new Assert\Type('string')],
      'successUrl' => [new Assert\NotBlank(), new Assert\Type('string')],
      'failedUrl' => [new Assert\NotBlank(), new Assert\Type('string')],
      'paymentMethod' => [new Assert\NotBlank(), new Assert\Type('string')],
    ]);

    $errors = $this->validator->validateFields($data, $constraints);
    if (count($errors) > 0) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
    }

    $orderDetail = $this->orderService->getOrderDetailsById($data['order_id'], $context->getContext());
    $successUrl = $data['successUrl'] . '?orderId=' . $data['order_id'];
    $failedUrl = $data['failedUrl'];

    $this->blueSnapTransactionService->addTransaction($data['order_id'], $data['paymentMethod'], $data['order_id'], TransactionStatuses::PENDING->value, $context->getContext());
    $link = $this->paymentLinkService->generatePaymentLink($orderDetail, $successUrl, $failedUrl, $context->getContext(), true, $context->getSalesChannelId());

    return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $link));
  }

  #[Route(path: '/store-api/bluesnap/create-transaction', name: 'store-api.bluesnap.createTransaction', methods: ['POST'])]
  public function createTransaction(Request $request, SalesChannelContext $context): BlueSnapApiResponse
  {
    $data = $request->request->all();
    $constraints = new Assert\Collection([
      'orderId' => [new Assert\NotBlank(), new Assert\Type('string')],
      'transactionId' => [new Assert\NotBlank(), new Assert\Type('string')],
      'paymentMethod' => [new Assert\NotBlank(), new Assert\Type('string')],
    ]);

    $errors = $this->validator->validateFields($data, $constraints);
    if (count($errors) > 0) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
    }
    $this->blueSnapTransactionService->addTransaction($data['orderId'], $data['paymentMethod'], $data['transactionId'], TransactionStatuses::PAID->value, $context->getContext());

    return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, 'Transaction created!'));
  }

  #[Route(path: '/store-api/handle-payment', name: 'store-api.payment.handle', methods: ['GET', 'POST'])]
  public function handlePayment(Request $request, SalesChannelContext $context): BlueSnapApiResponse|HandlePaymentMethodRouteResponse
  {
    $data = $request->request->all();

    $order = $this->orderService->getOrderDetailsById($data['orderId'], $context->getContext());
    if ($order) {
      $orderTransaction = $order->getTransactions()->first();
      $paymentMethod = $orderTransaction->getPaymentMethod();

      $bluesnapPaymentMethods = new PaymentMethods();
      $handlers = [];
      foreach ($bluesnapPaymentMethods::PAYMENT_METHODS as $method) {
        $method = new $method;
        $handlers[] = $method->getPaymentHandler();
      }
      if (!in_array($paymentMethod->getHandlerIdentifier(), $handlers)) {
        return $this->handlePaymentMethodRoute->load($request, $context);
      }
    }

    $constraints = new Assert\Collection([
      'orderId' => [new Assert\NotBlank(), new Assert\Type('string')],
      'finishUrl' => [new Assert\NotBlank(), new Assert\Type('string')],
      'errorUrl' => [new Assert\NotBlank(), new Assert\Type('string')],
    ]);

    $errors = $this->validator->validateFields($data, $constraints);
    if (count($errors) > 0) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
    }
    $transactionId = $this->orderService->getOrderTransactionIdByOrderId($data['orderId'], $context->getContext());
    $bluesnapTransaction = $this->blueSnapTransactionService->getTransactionByOrderId($data['orderId'], $context->getContext());
    if ($bluesnapTransaction->getStatus() != 'paid') {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $data['errorUrl']));
    }
    $this->transactionStateHandler->paid($transactionId, $context->getContext());

    return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, $data['finishUrl']));
  }

  #[Route(path: '/store-api/bluesnap/re-send-payment-link', name: 'store-api.bluesnap.reSendPaymentLink', methods: ['POST'])]
  public function reSendPaymentLink(Request $request, Context $context): BlueSnapApiResponse
  {
    $data = $request->request->all();
    $constraints = new Assert\Collection([
      'orderId' => [new Assert\NotBlank(), new Assert\Type('string')],
    ]);
    $errors = $this->validator->validateFields($data, $constraints);
    if (count($errors) > 0) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, $errors), 400);
    }

    $order = $this->orderService->getOrderDetailsById($data['orderId'], $context);
    if (!$order) {
      return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(false, 'No Order Found!'), 400);
    }
    $paymentLink = $this->paymentLinkService->generatePaymentLink($order, 'payment-link-success', 'payment-link-fail', $context, false, $order->getSalesChannelID());
    $this->paymentLinkService->storePaymentLink($data['orderId'], $paymentLink, $context);
    $this->paymentLinkService->sendEmail($paymentLink, $order, $order->getSalesChannelID(), $context);

    return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, 'Payment link sent!'));
  }

  #[Route(path: '/store-api/bluesnap/test-connection', name: 'store-api.bluesnap.testConnection', methods: ['POST'])]
  public function testConnection(Request $request, Context $context): BlueSnapApiResponse
  {


    return new BlueSnapApiResponse(new BlueSnapApiResponseStruct(true, 'Test connection!'));
  }

}
