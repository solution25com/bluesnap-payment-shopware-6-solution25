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

      $lineItems[] = [
        "id" => (string)$product,
        "quantity" => $quantity,
        "label" => $productName,
        "description" => $productDescription,
        "amount" =>  $unitPrice * $quantity,
      ];
    }

    $shippingCost = $order->getShippingCosts()->getTotalPrice();
    if($shippingCost){
      $lineItems[] = [
        "id" => Uuid::randomHex(),
        "quantity" => 1,
        "label" => 'Shipping Cost',
        "amount" => $shippingCost,
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

    return EnvironmentUrl::CHECKOUT_LINK->value . '/checkout/?jwt=' . $responseData['jwt'];
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