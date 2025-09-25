<?php

namespace BlueSnap\Service;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use BlueSnap\Library\Constants\EnvironmentUrl;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RequestStack;

class PaymentLinkService
{
  private EntityRepository $paymentLinkRepository;
  private BlueSnapApiClient $blueSnapApiClient;
  private BlueSnapConfig $blueSnapConfig;
  private RequestStack $requestStack;
  private AbstractMailService $mailService;
  private EntityRepository $mailTemplateRepository;
  private SystemConfigService $systemConfigService;
  private EntityRepository $orderRepository;
  private OrderService $orderService;

  public function __construct(
    EntityRepository    $paymentLinkRepository,
    BlueSnapApiClient   $blueSnapApiClient,
    BlueSnapConfig      $blueSnapConfig,
    RequestStack        $requestStack,
    AbstractMailService $mailService,
    EntityRepository    $mailTemplateRepository,
    SystemConfigService $systemConfigService,
    EntityRepository    $orderRepository,
    OrderService        $orderService
  )
  {
    $this->paymentLinkRepository = $paymentLinkRepository;
    $this->blueSnapApiClient = $blueSnapApiClient;
    $this->blueSnapConfig = $blueSnapConfig;
    $this->requestStack = $requestStack;
    $this->mailService = $mailService;
    $this->mailTemplateRepository = $mailTemplateRepository;
    $this->systemConfigService = $systemConfigService;
    $this->orderRepository = $orderRepository;
    $this->orderService = $orderService;
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

  public function generatePaymentLink($order, $successUrl, $cancelUrl, Context $context, $api = false, string $salesChannelId = ''): string
  {
    $taxRule = $this->blueSnapConfig->getConfig('taxRule', $salesChannelId);
    $lineItems = [];

    foreach ($order->getLineItems() as $lineItem) {
      $payload = $lineItem->getPayload();

      // fix for bundle product plugin (ignore line items)
      if (isset($payload['zeobvCustomLineItemType']) && $payload['zeobvCustomLineItemType'] === 'bundle_product_item') {
        continue;
      }

      $product = $lineItem->getReferencedId();
      $quantity = $lineItem->getQuantity();
      $unitPrice = $lineItem->getPrice()->getUnitPrice();
      $productName = $lineItem->getLabel();
      $productDescription = $lineItem->getDescription() ?? 'No description available';

      $lineItems[] = [
        "payload" => $payload,
        "id" => (string)$product,
        "quantity" => $quantity,
        "label" => $productName,
        "description" => $productDescription,
        "amount" => round($unitPrice * $quantity, 2),
      ];

      if ($taxRule === 'EU') {
        $shippingCost = $order->getShippingCosts()->getTotalPrice();
        if ($shippingCost > 0) {
          $lineItems[] = [
            "id" => Uuid::randomHex(),
            "quantity" => 1,
            "label" => 'Shipping Cost',
            "amount" => round($shippingCost, 2),
          ];
        }
      } else {
        $calculatedTax = $order->getLineItems()->getPrices()->getCalculatedTaxes()->getAmount();
        $shippingTax = $order->getShippingCosts()->getCalculatedTaxes()->getAmount();

        if ($shippingTax && $calculatedTax) {
          $lineItems[] = [
            "id" => Uuid::randomHex(),
            "quantity" => 1,
            "label" => 'Tax',
            "amount" => round($calculatedTax + $shippingTax, 2),
          ];
        } elseif ($shippingTax != 0) {
          $lineItems[] = [
            "id" => Uuid::randomHex(),
            "quantity" => 1,
            "label" => 'Tax',
            "amount" => round($shippingTax, 2),
          ];
        } elseif ($calculatedTax != 0) {
          $lineItems[] = [
            "id" => Uuid::randomHex(),
            "quantity" => 1,
            "label" => 'Tax',
            "amount" => round($calculatedTax, 2),
          ];
        }

        $shippingCost = $order->getShippingCosts()->getTotalPrice();
        if ($shippingCost > 0) {
          $lineItems[] = [
            "id" => Uuid::randomHex(),
            "quantity" => 1,
            "label" => 'Shipping Cost',
            "amount" => round($shippingCost, 2),
          ];
        }
      }

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

    $includeLevelTwoThreeData = $this->blueSnapConfig->Level23DataConfigs($salesChannelId, $order->getOrderCustomer()->getCustomer()->getGroupId());

    if ($includeLevelTwoThreeData) {
      $displayData = $this->buildLevel3DisplayData($order, $context);
    } else {
      $displayData = [];
    }

    $encode = function ($data) {
      $b64 = base64_encode($data);
      return rtrim(strtr($b64, '+/', '-_'), '=');
    };
    $displayDataEncoded = $encode(json_encode($displayData));

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

    $checkoutLink = $this->blueSnapConfig->getConfig('mode', $salesChannelId) === 'live' ? EnvironmentUrl::CHECKOUT_LINK_LIVE->value : EnvironmentUrl::CHECKOUT_LINK_SANDBOX->value;
    $jwt = $responseData['jwt'];
    return $checkoutLink . '/checkout/?jwt=' . $jwt . '&displaydata=' . urlencode($displayDataEncoded);
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

  private function buildLevel3DisplayData($order, Context $context): array
  {
    $criteria = new Criteria([$order->getId()]);
    $criteria->addAssociation('billingAddress.country');

    $orderEntity = $this->orderRepository->search($criteria, $context)->first();
    if (!$orderEntity) {
      throw new \RuntimeException('Order not found: ' . $order->getId());
    }

    $billing = $orderEntity->getBillingAddress();
    $destinationCountryCode = $billing?->getCountry()?->getIso();
    $destinationZipCode = $billing?->getZipcode();

    $totalTaxAmount = 0.0;
    $weightedSum = 0.0;
    $calculatedTaxes = $order->getPrice()->getCalculatedTaxes();

    foreach ($calculatedTaxes as $tax) {
      $taxAmount = $tax->getTax();
      $totalTaxAmount += $taxAmount;
      $weightedSum += $taxAmount * $tax->getTaxRate();
    }
    $taxRate = $totalTaxAmount > 0 ? round($weightedSum / $totalTaxAmount, 2) : 0.0;

    $shippingCost = $order->getShippingCosts()->getTotalPrice();
    $shipFromZipCode = $this->systemConfigService->get('BlueSnap.config.shipFromZipCode');
    $taxType = $this->systemConfigService->get('BlueSnap.config.taxType') ?? null;

    $level3DataItems = [];
    $cartDiscountAmount = 0.0;
    $isItemGross = $order->getTaxStatus() === CartPrice::TAX_STATE_GROSS;

    foreach ($order->getLineItems() as $lineItem) {

      $productId = $lineItem->getReferencedId();
      $quantity = $lineItem->getQuantity();
      $unitPrice = $lineItem->getPrice()->getUnitPrice();
      $price = $lineItem->getPrice();

      if ($lineItem->getType() === LineItem::PROMOTION_LINE_ITEM_TYPE) {
        $cartDiscountAmount += abs($price?->getTotalPrice() ?? 0.0);
      }

      $itemDiscountAmount = 0.0;
      $listPrice = $price->getListPrice();
      if ($listPrice !== null) {
        $itemDiscountAmount = ($listPrice->getPrice() - $price->getUnitPrice()) * $quantity;
      }

      $itemTaxAmount = 0.0;
      $itemTaxRate = 0.0;
      foreach ($price->getCalculatedTaxes() as $tax) {
        $itemTaxAmount += $tax->getTax();
        $itemTaxRate = $tax->getTaxRate();
      }

      $discountIndicator = $listPrice && $listPrice->getPrice() > $price->getUnitPrice() ? 'Y' : 'N';
      
      $product = $this->orderService->getProduct($productId, $context);
      $unitOfMeasure = $product->getUnit()?->getShortCode() ?? 'N/A';


      $level3DataItems[] = [
        'commodityCode' => '',
        'description' => $lineItem->getLabel(),
        'discountAmount' => round($itemDiscountAmount, 2),
        'discountIndicator' => $discountIndicator,
        'grossNetIndicator' => $isItemGross ? 'Y' : 'N',
        'itemQuantity' => $quantity,
        'lineItemTotal' => $price->getTotalPrice(),
        'productCode' => $product->getProductNumber(),
        'taxAmount' => $itemTaxAmount,
        'taxRate' => $itemTaxRate,
        'taxType' => $taxType,
        'unitCost' => $unitPrice,
        'unitOfMeasure' => $unitOfMeasure,
      ];
    }

    return [
      'level3Data' => [
        'customerReferenceNumber' => $order->getOrderCustomer()->getCustomerNumber(),
        'salesTaxAmount' => $totalTaxAmount,
        'destinationCountryCode' => $destinationCountryCode,
        'destinationZipCode' => $destinationZipCode,
        'discountAmount' => $cartDiscountAmount,
        'freightAmount' => $shippingCost,
        'shipFromZipCode' => $shipFromZipCode,
        'taxAmount' => $totalTaxAmount,
        'taxRate' => $taxRate,
        'level3DataItems' => $level3DataItems,
      ],
    ];
  }
}