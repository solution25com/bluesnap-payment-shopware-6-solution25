<?php

namespace BlueSnap\Service;

use BlueSnap\Library\Constants\EnvironmentUrl;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Symfony\Component\HttpFoundation\RequestStack;

class PaymentLinkService
{
  private EntityRepository $paymentLinkRepository;
  private BlueSnapApiClient $blueSnapApiClient;
  private BlueSnapConfig $blueSnapConfig;
  private RequestStack $requestStack;
  private AbstractMailService $mailService;
  private EntityRepository $mailTemplateRepository;

  public function __construct(
    EntityRepository    $paymentLinkRepository,
    BlueSnapApiClient   $blueSnapApiClient,
    BlueSnapConfig      $blueSnapConfig,
    RequestStack        $requestStack,
    AbstractMailService $mailService,
    EntityRepository    $mailTemplateRepository,
  )
  {
    $this->paymentLinkRepository = $paymentLinkRepository;
    $this->blueSnapApiClient = $blueSnapApiClient;
    $this->blueSnapConfig = $blueSnapConfig;
    $this->requestStack = $requestStack;
    $this->mailService = $mailService;
    $this->mailTemplateRepository = $mailTemplateRepository;
  }

  public function storePaymentLink(string $orderId, string $paymentLink, Context $context): void
  {

    $this->paymentLinkRepository->create([
      [
        'id' => Uuid::randomHex(),
        'order_id' => $orderId,
        'link' => $paymentLink,
        'created_at' => (new \DateTime())->format('Y-m-d H:i:s')
      ]
    ], $context);
  }

  public function searchPaymentLink(string $orderId, Context $context): null|Entity
  {
    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('order_id', $orderId));
    return $this->paymentLinkRepository->search($criteria, $context)->first();
  }

  public function generatePaymentLink($order, $successUrl, $cancelUrl, $api = false, string $salesChannelId = ''): string
  {
    $lineItems = [];

    foreach ($order->getLineItems() as $lineItem) {
      $product = $lineItem->getReferencedId();
      $quantity = $lineItem->getQuantity();
      $unitPrice = $lineItem->getPrice()->getUnitPrice();
      $productName = $lineItem->getLabel();
      $productDescription = $lineItem->getDescription() ?? 'No description available';
      $calculatedTax = $lineItem->getPrice()->getCalculatedTaxes()->getAmount();
      $shippingTax = $order->getShippingCosts()->getCalculatedTaxes()->getAmount();

      $lineItems[] = [
        "id" => (string)$product,
        "quantity" => $quantity,
        "label" => $productName,
        "description" => $productDescription,
        "amount" => round($unitPrice * $quantity, 2),
      ];
    }
    if($shippingTax && $calculatedTax){
      $lineItems[] = [
        "id" => Uuid::randomHex(),
        "quantity" => 1,
        "label" => 'Tax',
        "amount" => round($calculatedTax + $shippingTax, 2),
      ];
    }
    elseif($shippingTax != 0){
      $lineItems[] = [
        "id" => Uuid::randomHex(),
        "quantity" => 1,
        "label" => 'Tax',
        "amount" => round($shippingTax, 2),
      ];
    }
    elseif($calculatedTax != 0){
      $lineItems[] = [
        "id" => Uuid::randomHex(),
        "quantity" => 1,
        "label" => 'Tax',
        "amount" => round($calculatedTax, 2),
      ];
    }
    $shippingCost = $order->getShippingCosts()->getTotalPrice();
    if ($shippingCost) {
      $lineItems[] = [
        "id" => Uuid::randomHex(),
        "quantity" => 1,
        "label" => 'Shipping Cost',
        "amount" => round($shippingCost, 2),
      ];
    }

    $request = $this->requestStack->getCurrentRequest();

    if (!$api) {
      $baseUrl = '';
      if ($request) {
        $baseUrl = $request->getSchemeAndHttpHost();
      }
      $successUrl = "$baseUrl/" . $successUrl;
      $cancelUrl = "$baseUrl/" . $cancelUrl;

      $enableFeLink = $this->blueSnapConfig->getConfig('adminFeLinks', $salesChannelId);
      if ($enableFeLink) {
        $successUrl = $this->blueSnapConfig->getConfig('successUrl', $salesChannelId);
        $cancelUrl = $this->blueSnapConfig->getConfig('failedUrl', $salesChannelId);
      }
    }

    $response = $this->blueSnapApiClient->hostedCheckout([
      "mode" => "one_time",
      'expH' => '8760',
      "onBehalf" => "false",
      "currency" => $order->getCurrency()->getIsoCode(),
      "successUrl" => $successUrl,
      "cancelUrl" => $cancelUrl,
      "merchantTransactionId" => $order->getId(),
      "lineItems" => $lineItems,
    ], $salesChannelId);

    $responseData = json_decode($response, true);

    $checkoutLink = $this->blueSnapConfig->getConfig('mode', $salesChannelId) === 'live' ? EnvironmentUrl::CHECKOUT_LINK_LIVE->value : EnvironmentUrl::CHECKOUT_LINK_SANDBOX->value ;
    return $checkoutLink . '/checkout/?jwt=' . $responseData['jwt'];
  }

  public function sendEmail(string $link, Entity $order, string $salesChannelId, Context $context): void
  {
    $data = new DataBag();

    $fullName = $order->getOrderCustomer()->getFirstName() . ' ' . $order->getOrderCustomer()->getLastName();

    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', 'admin.payment.link'));
    $criteria->setLimit(1);

    $mailTemplate = $this->mailTemplateRepository->search($criteria, $context)->first();

    $data->set('recipients', [$order->getOrderCustomer()->getEmail() => $fullName]);
    $data->set('senderName', $mailTemplate->getSenderName());
    $data->set('salesChannelId', $salesChannelId);
    $data->set('contentHtml', $mailTemplate->getContentHtml());
    $data->set('contentPlain', $mailTemplate->getContentPlain());

    $data->set('subject', $mailTemplate->getSubject());

    $content = [
      'paymentLink' => $link,
      'firstName' => $order->getOrderCustomer()->getFirstName(),
      'lastName' => $order->getOrderCustomer()->getLastName(),
      'orderNumber' => $order->getOrderNumber(),
    ];

    $this->mailService->send($data->all(), $context, $content);
  }
}