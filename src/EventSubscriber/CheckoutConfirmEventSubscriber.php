<?php

namespace BlueSnap\EventSubscriber;

use BlueSnap\Core\Checkout\Cart\BlueSnapSurchargeContext;
use BlueSnap\Gateways\ApplePay;
use BlueSnap\Gateways\CreditCard;
use BlueSnap\Gateways\GooglePay;
use BlueSnap\Gateways\LinkPayment;
use BlueSnap\Library\Constants\EnvironmentUrl;
use BlueSnap\Service\BlueSnapApiClient;
use BlueSnap\Service\BlueSnapConfig;
use BlueSnap\Service\VaultedShopperService;
use BlueSnap\Storefront\Struct\CheckoutTemplateCustomData;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent;

class CheckoutConfirmEventSubscriber implements EventSubscriberInterface
{
    private BlueSnapApiClient $blueSnapClient;
    private VaultedShopperService $vaultedShopperService;
    private BlueSnapConfig $blueSnapConfig;
    private BlueSnapSurchargeContext $surchargeContext;

    public function __construct(
        BlueSnapApiClient $blueSnapClient,
        BlueSnapConfig $blueSnapConfig,
        VaultedShopperService $vaultedShopperService,
        BlueSnapSurchargeContext $surchargeContext
    ) {
        $this->blueSnapClient = $blueSnapClient;
        $this->blueSnapConfig = $blueSnapConfig;
        $this->vaultedShopperService = $vaultedShopperService;
        $this->surchargeContext = $surchargeContext;
    }

    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'addPaymentMethodSpecificFormFields',
            SalesChannelContextSwitchEvent::class => 'onPaymentMethodSwitch',
        ];
    }

    private function getCreditCardPageFields(CheckoutConfirmPageLoadedEvent $event): array
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        $isSurchargeActive = (bool)$this->blueSnapConfig->getConfig('useSurcharge', $salesChannelId);
        $customer = $salesChannelContext->getCustomer();
        $customerId = $customer?->getId();
        $flow = $this->blueSnapConfig->getConfig('flow', $salesChannelId);
        $surcharge = $this->getSurchargeDataFromCart($event);

        $queryParam = [];
        $isCardSaved = false;
        $shopperName = '';
        $shopperLastName = '';
        $shopperLast4Digits = '';
        $shopperCardType = '';
        $vaultedShopperId = '';

        $vaultedShopperEnable = $this->blueSnapConfig->getConfig('vaultedShopper', $salesChannelId);
        if ($vaultedShopperEnable && $customerId && !$this->surchargeContext->getPfToken()) {
            $isCardSaved = $this->vaultedShopperService->vaultedShopperExist($event->getContext(), $customerId);
            $vaultedShopperId = $this->vaultedShopperService->getVaultedShopperIdByCustomerId($event->getContext(), $customerId);
            if ($vaultedShopperId) {
                $this->surchargeContext->setVaultedCustomerId($vaultedShopperId);
                $queryParam['shopperId'] = $vaultedShopperId;

                $shopperData = $this->blueSnapClient->getVaultedShopper($vaultedShopperId, $salesChannelId);
                $decodedData = json_decode($shopperData, true);

                $shopperName = $decodedData['paymentSources']['creditCardInfo'][0]['billingContactInfo']['firstName'] ?? '';
                $shopperLastName = $decodedData['paymentSources']['creditCardInfo'][0]['billingContactInfo']['lastName'] ?? '';
                $shopperLast4Digits = $decodedData['paymentSources']['creditCardInfo'][0]['creditCard']['cardLastFourDigits'] ?? '';
                $shopperCardType = $decodedData['paymentSources']['creditCardInfo'][0]['creditCard']['cardType'] ?? '';
            }
        }
        $surchargeData = $this->surchargeContext->getSurchargeData();
        $surchargeAmount = (is_array($surcharge) ? ($surcharge['bluesnap_surcharge_amount'] ?? null) : null) ?? (is_array($surchargeData) ? ($surchargeData['bluesnap_surcharge_amount'] ?? null) : null);
        $surchargeToken = (is_array($surcharge) ? ($surcharge['bluesnap_surcharge_token'] ?? null) : null) ?? (is_array($surchargeData) ? ($surchargeData['bluesnap_surcharge_token'] ?? null) : null);
        $blueSnapItems = $event->getPage()->getCart()->getLineItems()->filterType('bluesnap_surcharge')->first();
        if ($blueSnapItems) {
            $bluesnapBaseAmount = $blueSnapItems->getPrice()->getTotalPrice();
        }

        $existingSurchargeAmount = $blueSnapItems !== null ? $blueSnapItems->getPrice()->getTotalPrice() : 0.0;
        $securedAmount = round($event->getPage()->getCart()->getPrice()->getTotalPrice() - $existingSurchargeAmount, 2);

        $surchargePfToken = $this->surchargeContext->getPfToken() ?? null;
        $pfToken = ($surchargePfToken !== null && $surchargePfToken !== '') ? $surchargePfToken : (is_array($surcharge) ? ($surcharge['pfToken'] ?? null) : null);
        if (!is_string($pfToken) || $pfToken === '') {
            $pfTokenResponse = $this->blueSnapClient->makeTokenRequest($queryParam, $salesChannelId);
            $pfToken = is_array($pfTokenResponse) ? null : $pfTokenResponse;
        }

        return  [
            'template' => '@Storefront/bluesnap/credit-card.html.twig',
            'isGuestLogin' => $customer?->getGuest() ?? true,
            'flow' => $flow,
            'vaultedShopperEnable' => $vaultedShopperEnable,
            'pfToken' => $pfToken,
            'gateway' => 'creditCard',
            'isSavedCard' => $isCardSaved,
            'vaultedShopperId' => $vaultedShopperId,
            'securedAmount' => $securedAmount,
            'securedBaseAmount' => $bluesnapBaseAmount ?? null,
            'securedCurrency' => $salesChannelContext->getCurrency()->getIsoCode(),
            'securedFirstName' => $customer?->getFirstName() ?? '',
            'securedLastName' => $customer?->getLastName() ?? '',
            'shopperName' => $shopperName,
            'shopperLastName' => $shopperLastName,
            'shopperLast4Digits' => $shopperLast4Digits,
            'shopperCardType' => $shopperCardType,
            'isSurchargeActive' => $isSurchargeActive,
            'surchargeAmount' => $surchargeAmount,
            'surchargeToken' => $surchargeToken,
            'surchargeReference' => $surcharge['reference'] ?? (is_array($surchargeData) ? ($surchargeData['bluesnap_surcharge_reference'] ?? null) : null),
            'surchargePfToken' => $this->surchargeContext->getPfToken() ?? null,
            'surchargeCardType' => $this->surchargeContext->getCardType(),
            'threeDS' => $this->blueSnapConfig->getConfig('threeDS', $salesChannelId),
            'js_link' => $this->blueSnapConfig->getConfig('mode', $salesChannelId) === 'live' ? EnvironmentUrl::BLUESNAP_JS_LIVE->value : EnvironmentUrl::BLUESNAP_JS_SANDBOX->value,
        ];
    }


    private function getGooglePayPageFields(CheckoutConfirmPageLoadedEvent $event): array
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        $flow = $this->blueSnapConfig->getConfig('flow', $salesChannelId);
        $surcharge = $this->getSurchargeDataFromCart($event);
        $pfToken = $surcharge['pfToken'] ?? null;
        if (!is_string($pfToken) || $pfToken === '') {
            $pfTokenResponse = $this->blueSnapClient->makeTokenRequest([], $salesChannelId);
            $pfToken = is_array($pfTokenResponse) ? null : $pfTokenResponse;
        }

        return [
            'template' => '@Storefront/bluesnap/google-pay.html.twig',
            'isGuestLogin' => $salesChannelContext->getCustomer()?->getGuest() ?? true,
            'flow' => $flow,
            'pfToken' => $pfToken,
            'merchantId' => $this->blueSnapConfig->getConfig('merchantId', $salesChannelId),
            'googleMerchantId' => $this->blueSnapConfig->getConfig('merchantGoogleId', $salesChannelId),
            'mode' => $this->blueSnapConfig->getConfig('mode', $salesChannelId),
            'gateway' => 'googlePay',
            'surchargeAmount' => $surcharge['amount'] ?? null,
            'surchargeToken' => $surcharge['token'] ?? null,
            'surchargeReference' => $surcharge['reference'] ?? null,
        ];
    }

    private function getApplePayPageFields(CheckoutConfirmPageLoadedEvent $event): array
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        $flow = $this->blueSnapConfig->getConfig('flow', $salesChannelId);
        $surcharge = $this->getSurchargeDataFromCart($event);
        $pfToken = $surcharge['pfToken'] ?? null;
        if (!is_string($pfToken) || $pfToken === '') {
            $pfTokenResponse = $this->blueSnapClient->makeTokenRequest([], $salesChannelId);
            $pfToken = is_array($pfTokenResponse) ? null : $pfTokenResponse;
        }

        return [
            'template' => '@Storefront/bluesnap/apple-pay.html.twig',
            'isGuestLogin' => $salesChannelContext->getCustomer()?->getGuest() ?? true,
            'pfToken' => $pfToken,
            'flow' => $flow,
            'merchantId' => $this->blueSnapConfig->getConfig('merchantId', $salesChannelId),
            'gateway' => 'applePay',
            'surchargeAmount' => $surcharge['amount'] ?? null,
            'surchargeToken' => $surcharge['token'] ?? null,
            'surchargeReference' => $surcharge['reference'] ?? null,
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

    private function getSurchargeDataFromCart(CheckoutConfirmPageLoadedEvent $event): ?array
    {
        $cart = $event->getPage()->getCart();
        $surcharge = $cart->getExtension('bluesnap_surcharge')
            ?? $cart->getPrice()->getExtension('bluesnap_surcharge');
        if ($surcharge === null || !method_exists($surcharge, 'get')) {
            return null;
        }

        $token = (string) ($surcharge->get('token') ?? $surcharge->get('surchargeToken') ?? '');
        if ($token === '') {
            return null;
        }

        return [
            'amount' => (float) $surcharge->get('amount'),
            'token' => $token,
            'reference' => $surcharge->get('reference'),
            'pfToken' => $surcharge->get('pfToken'),
        ];
    }

    public function onPaymentMethodSwitch(SalesChannelContextSwitchEvent $event): void
    {
        if ($event->getRequestDataBag()->get('paymentMethodId') === null) {
            return;
        }
        $this->surchargeContext->clearPfToken();
        $this->surchargeContext->clearVaultedCustomerId();
    }
}
