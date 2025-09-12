<?php

namespace solu1BluesnapPayment\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class OrderService
{
  private EntityRepository $orderRepository;
  private EntityRepository $orderTransactionRepository;
  private EntityRepository $productRepository;
  private SystemConfigService $systemConfigService;

  public function __construct(
    EntityRepository    $orderRepository,
    EntityRepository    $orderTransactionRepository,
    EntityRepository    $productRepository,
    SystemConfigService $systemConfigService
  )
  {
    $this->orderRepository = $orderRepository;
    $this->orderTransactionRepository = $orderTransactionRepository;
    $this->productRepository = $productRepository;
    $this->systemConfigService = $systemConfigService;
  }

  public function getProduct(string $productId, Context $context): ?ProductEntity
  {
    $criteria = new Criteria([$productId]);
    $criteria->addAssociation('unit');
    return $this->productRepository->search($criteria, $context)->first();
  }

  public function getOrderDetailsById(string $orderId, Context $context)
  {
    $criteria = new Criteria([$orderId]);
    $criteria->addAssociation('lineItems');
    $criteria->addAssociation('transactions');
    $criteria->addAssociation('transactions.paymentMethod');
    $criteria->addAssociation('currency');
    $criteria->addAssociation('orderCustomer.customer');
    return $this->orderRepository->search($criteria, $context)->first();
  }

  public function getOrderTransactionsById(string $transactionId, Context $context)
  {
    $criteria = new Criteria([$transactionId]);
    $criteria->addAssociation('order');
    $criteria->addAssociation('order.lineItems');
    $criteria->addAssociation('order.orderCustomer.customer');
    $criteria->addAssociation('order.orderCustomer.customer.defaultShippingAddress');
    $criteria->addAssociation('order.orderCustomer.customer.defaultShippingAddress.country');
    $criteria->addAssociation('order.currency');
    $criteria->addAssociation('order.billingAddress');
    $criteria->addAssociation('order.billingAddress.country');
    $criteria->addAssociation('paymentMethod');

    return $this->orderTransactionRepository->search($criteria, $context)->first();
  }

  public function getOrderTransactionIdByOrderId($orderId, $context)
  {
    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('orderId', $orderId));
    $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();
    if ($orderTransaction) {
      return $orderTransaction->getId();
    }
    return null;
  }

  public function buildLevel3Data(array $cartData, Context $context): array
  {
    $level3Data = [
      'customerReferenceNumber' => $cartData['customerNumber'] ?? null,
      'salesTaxAmount' => $cartData['taxAmount'] ?? 0.0,
      'freightAmount' => $cartData['shippingCost'] ?? 0.0,
      'destinationZipCode' => $cartData['shipping']['zipcode'] ?? null,
      'destinationCountryCode' => $cartData['shipping']['countryIso'] ?? null,
      'shipFromZipCode' => $this->systemConfigService->get('BlueSnap.config.shipFromZipCode'),
      'discountAmount' => array_sum(array_column($cartData['lineItems'], 'discountAmount')),
      'taxAmount' => $cartData['taxAmount'] ?? 0.0,
      'taxRate' => $cartData['taxRate'] ?? 0.0,
      'level3DataItems' => [],
    ];

    foreach ($cartData['lineItems'] as $item) {
      if ($item['type'] === LineItem::PROMOTION_LINE_ITEM_TYPE) {
        continue;
      }

      $product = $this->getProduct($item['referencedId'], $context);
      if (!$product) continue;

      $level3Data['level3DataItems'][] = [
        'lineItemTotal' => $item['totalPrice'],
        'description' => $item['label'],
        'discountAmount' => $item['discountAmount'],
        'productCode' => $product->getProductNumber(),
        'itemQuantity' => $item['quantity'],
        'taxAmount' => $item['taxAmount'],
        'taxRate' => $item['taxRate'],
        'unitOfMeasure' => $product->getUnit()?->getShortCode() ?? 'N/A',
        'discountIndicator' => $item['discountIndicator'],
        'grossNetIndicator' => $context->getTaxState() === CartPrice::TAX_STATE_GROSS ? 'Y' : 'N',
        'unitCost' => $item['unitPrice'],
        'taxType' => $this->systemConfigService->get('BlueSnap.config.taxType'),
      ];
    }

    return $level3Data;
  }

  public function extractLVL2_3DataFromCart(Cart $cart, SalesChannelContext $context): array
  {
    $customer = $context->getCustomer();
    $shippingAddress = $customer?->getDefaultShippingAddress();
    $cartPrice = $cart->getPrice();
    $shippingCost = $cart->getShippingCosts()->getTotalPrice();

    return $this->extractLVL2_3Data($cart->getLineItems(), $cartPrice, $customer, $shippingAddress, $shippingCost);
  }

  public function extractLVL2_3DataFromOrder(OrderEntity $order): array
  {
    $customer = $order->getOrderCustomer()->getCustomer();
    $shippingAddress = $customer?->getDefaultShippingAddress();
    $cartPrice = $order->getPrice();
    $shippingCost = $order->getShippingCosts()->getTotalPrice();

    return $this->extractLVL2_3Data($order->getLineItems(), $cartPrice, $customer, $shippingAddress, $shippingCost);
  }

  private function extractLVL2_3Data($lineItems, $totalPrice, $customer, $shippingAddress, $shippingCost): array
  {
    $items = [];
    foreach ($lineItems as $item) {
      $itemPrice = $item->getPrice();
      $unitPrice = $itemPrice?->getUnitPrice() ?? 0.0;
      $itemTotalPrice = $itemPrice?->getTotalPrice() ?? 0.0;
      $listPrice = $itemPrice?->getListPrice()?->getPrice();

      $itemTaxes = $itemPrice?->getCalculatedTaxes() ?? [];

      //item-level total tax and weighted tax rate here
      $itemTaxAmount = 0.0;
      $itemWeightedSum = 0.0;
      foreach ($itemTaxes as $tax) {
        $itemTaxAmount += $tax->getTax();
        $itemWeightedSum += $tax->getTax() * $tax->getTaxRate();
      }
      $itemTaxRate = $itemTaxAmount > 0 ? round($itemWeightedSum / $itemTaxAmount, 2) : 0.0;

      // Discount info
      $discountAmount = ($listPrice !== null && $listPrice > $unitPrice) ? ($listPrice - $unitPrice) * $item->getQuantity() : 0.0;
      $discountIndicator = $discountAmount > 0 ? 'Y' : 'N';

      $items[] = [
        'type' => $item->getType(),
        'quantity' => $item->getQuantity(),
        'label' => $item->getLabel(),
        'referencedId' => $item->getReferencedId(),
        'unitPrice' => $unitPrice,
        'totalPrice' => $itemTotalPrice,
        'listPrice' => $listPrice,
        'taxAmount' => $itemTaxAmount,
        'taxRate' => $itemTaxRate,
        'discountAmount' => $discountAmount,
        'discountIndicator' => $discountIndicator,
      ];
    }

    // Cart-level tax sum
    $cartTaxes = $totalPrice->getCalculatedTaxes() ?? [];
    $totalTaxAmount = 0.0;
    $weightedSum = 0.0;
    foreach ($cartTaxes as $tax) {
      $totalTaxAmount += $tax->getTax();
      $weightedSum += $tax->getTax() * $tax->getTaxRate();
    }
    $taxRate = $totalTaxAmount > 0 ? round($weightedSum / $totalTaxAmount, 2) : 0.0;

    return [
      'customerNumber' => $customer?->getCustomerNumber(),
      'shipping' => [
        'zipcode' => $shippingAddress?->getZipcode(),
        'countryIso' => $shippingAddress?->getCountry()?->getIso(),
        'city' => $shippingAddress?->getCity(),
      ],
      'shippingCost' => $shippingCost,
      'totalPrice' => $totalPrice->getTotalPrice(),
      'taxAmount' => $totalTaxAmount,
      'taxRate' => $taxRate,
      'lineItems' => $items,
    ];
  }
}
