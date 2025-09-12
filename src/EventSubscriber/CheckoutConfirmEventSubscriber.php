<?php

namespace solu1BluesnapPayment\EventSubscriber;

use solu1BluesnapPayment\Gateways\ApplePay;
use solu1BluesnapPayment\Gateways\CreditCard;
use solu1BluesnapPayment\Gateways\GooglePay;
use solu1BluesnapPayment\Gateways\LinkPayment;
use solu1BluesnapPayment\Library\Constants\EnvironmentUrl;
use solu1BluesnapPayment\Service\BlueSnapApiClient;
use solu1BluesnapPayment\Service\BlueSnapConfig;
use solu1BluesnapPayment\Service\VaultedShopperService;
use solu1BluesnapPayment\Storefront\Struct\CheckoutTemplateCustomData;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use function Symfony\Component\String\b;

class CheckoutConfirmEventSubscriber implements EventSubscriberInterface
{
  private BlueSnapApiClient $blueSnapClient;
  private VaultedShopperService $vaultedShopperService;
  private BlueSnapConfig $blueSnapConfig;

  public function __construct(BlueSnapApiClient $blueSnapClient, BlueSnapConfig $blueSnapConfig, VaultedShopperService $vaultedShopperService)
  {
    $this->blueSnapClient = $blueSnapClient;
    $this->blueSnapConfig = $blueSnapConfig;
    $this->vaultedShopperService = $vaultedShopperService;
  }

  /**
   * @inheritDoc
   */
  public static function getSubscribedEvents(): array
  {
    return [
      CheckoutConfirmPageLoadedEvent::class => 'addPaymentMethodSpecificFormFields'
    ];
  }

  private function getCreditCardPageFields(CheckoutConfirmPageLoadedEvent $event): array
  {
    $salesChannelContext = $event->getSalesChannelContext();

    $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
    $customerId = $salesChannelContext->getCustomer()->getId();
    $flow = $this->blueSnapConfig->getConfig('flow', $salesChannelId);

    $queryParam = [];
    $isCardSaved = false;
    $shopperName = '';
    $shopperLastName = '';
    $shopperLast4Digits = '';
    $shopperCardType = '';
    $vaultedShopperId = '';

    $vaultedShopperEnable = $this->blueSnapConfig->getConfig('vaultedShopper', $salesChannelId);
    if ($vaultedShopperEnable) {
      $isCardSaved = $this->vaultedShopperService->vaultedShopperExist($event->getContext(), $customerId);
      $vaultedShopperId = $this->vaultedShopperService->getVaultedShopperIdByCustomerId($event->getContext(), $customerId);
      if ($vaultedShopperId) {

        $queryParam['shopperId'] = $vaultedShopperId;

        $shopperData = $this->blueSnapClient->getVaultedShopper($vaultedShopperId, $salesChannelId);
        $decodedData = json_decode($shopperData, true);

        $shopperName = $decodedData['paymentSources']['creditCardInfo'][0]['billingContactInfo']['firstName'] ?? '';
        $shopperLastName = $decodedData['paymentSources']['creditCardInfo'][0]['billingContactInfo']['lastName'] ?? '';
        $shopperLast4Digits = $decodedData['paymentSources']['creditCardInfo'][0]['creditCard']['cardLastFourDigits'] ?? '';
        $shopperCardType = $decodedData['paymentSources']['creditCardInfo'][0]['creditCard']['cardType'] ?? '';
      }
    }
    $pfToken = $this->blueSnapClient->makeTokenRequest($queryParam, $salesChannelId);
    return [
      'template' => '@Storefront/bluesnap/credit-card.html.twig',
      'isGuestLogin' => $salesChannelContext->getCustomer()->getGuest(),
      'flow' => $flow,
      'vaultedShopperEnable' => $vaultedShopperEnable,
      'pfToken' => $pfToken,
      'gateway' => 'creditCard',
      'isSavedCard' => $isCardSaved,
      'vaultedShopperId' => $vaultedShopperId,
      'securedAmount' => $event->getPage()->getCart()->getPrice()->getTotalPrice(),
      'securedCurrency' => $salesChannelContext->getCurrency()->getIsoCode(),
      'securedFirstName' => $salesChannelContext->getCustomer()->getFirstName(),
      'securedLastName' => $salesChannelContext->getCustomer()->getLastName(),
      'shopperName' => $shopperName,
      'shopperLastName' => $shopperLastName,
      'shopperLast4Digits' => $shopperLast4Digits,
      'shopperCardType' => $shopperCardType,
      'threeDS' => $this->blueSnapConfig->getConfig('threeDS', $salesChannelId),
      'js_link' => $this->blueSnapConfig->getConfig('mode', $salesChannelId) === 'live' ? EnvironmentUrl::BLUESNAP_JS_LIVE->value : EnvironmentUrl::BLUESNAP_JS_SANDBOX->value,
    ];
  }

  private function getGooglePayPageFields(CheckoutConfirmPageLoadedEvent $event): array
  {
    $salesChannelContext = $event->getSalesChannelContext();
    $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
    $flow = $this->blueSnapConfig->getConfig('flow', $salesChannelId);
    $pfToken = $this->blueSnapClient->makeTokenRequest([], $salesChannelId);
    return [
      'template' => '@Storefront/bluesnap/google-pay.html.twig',
      'isGuestLogin' => $salesChannelContext->getCustomer()->getGuest(),
      'flow' => $flow,
      'pfToken' => $pfToken,
      'merchantId' => $this->blueSnapConfig->getConfig('merchantId', $salesChannelId),
      'googleMerchantId' => $this->blueSnapConfig->getConfig('merchantGoogleId', $salesChannelId),
      'mode' => $this->blueSnapConfig->getConfig('mode', $salesChannelId),
      'gateway' => 'googlePay',
    ];
  }

  private function getApplePayPageFields(CheckoutConfirmPageLoadedEvent $event): array
  {
    $salesChannelContext = $event->getSalesChannelContext();
    $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
    $flow = $this->blueSnapConfig->getConfig('flow', $salesChannelId);
    $pfToken = $this->blueSnapClient->makeTokenRequest([], $salesChannelId);
    return [
      'template' => '@Storefront/bluesnap/apple-pay.html.twig',
      'isGuestLogin' => $salesChannelContext->getCustomer()->getGuest(),
      'pfToken' => $pfToken,
      'flow' => $flow,
      'merchantId' => $this->blueSnapConfig->getConfig('merchantId', $salesChannelId),
      'gateway' => 'applePay',
    ];

  }

  public function addPaymentMethodSpecificFormFields(CheckoutConfirmPageLoadedEvent $event): void
  {
    $templateFields = [];
    switch ($event->getSalesChannelContext()->getPaymentMethod()->getHandlerIdentifier()) {
      case CreditCard::class:
        $templateFields = $this->getCreditCardPageFields($event);
        break;
      case GooglePay::class:
        $templateFields = $this->getGooglePayPageFields($event);
        break;
      case ApplePay::class:
        $templateFields = $this->getApplePayPageFields($event);
        break;
    }

    $templateVariables = new CheckoutTemplateCustomData();
    $templateVariables->assign($templateFields);

    $pageObject = $event->getPage();
    $filteredPaymentMethods = $pageObject->getPaymentMethods()->filter(function (PaymentMethodEntity $paymentMethod) {
      return $paymentMethod->getHandlerIdentifier() !== LinkPayment::class;
    });
    $pageObject->setPaymentMethods($filteredPaymentMethods);

    $pageObject->addExtension(
      CheckoutTemplateCustomData::EXTENSION_NAME,
      $templateVariables
    );
  }
}
