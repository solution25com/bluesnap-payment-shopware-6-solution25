<?php

namespace BlueSnap\EventSubscriber;

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

class CheckoutConfirmEventSubscriber implements EventSubscriberInterface
{
    private BlueSnapApiClient $blueSnapClient;
    private VaultedShopperService $vaultedShopperService;
    private BlueSnapConfig $blueSnapConfig;

    public function __construct(BlueSnapApiClient $blueSnapClient, BlueSnapConfig $blueSnapConfig, VaultedShopperService $vaultedShopperService)
    {
        $this->blueSnapClient        = $blueSnapClient;
        $this->blueSnapConfig        = $blueSnapConfig;
        $this->vaultedShopperService = $vaultedShopperService;
    }

    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents()
    {
        return [
          CheckoutConfirmPageLoadedEvent::class => 'addPaymentMethodSpecificFormFields'
        ];
    }

    public function addPaymentMethodSpecificFormFields(CheckoutConfirmPageLoadedEvent $event): void
    {
        $context                = $event->getContext();
        $pageObject             = $event->getPage();
        $salesChannelContext    = $event->getSalesChannelContext();
        $salesChannelId         = $salesChannelContext->getSalesChannel()->getId();
        $selectedPaymentGateway = $salesChannelContext->getPaymentMethod();
        $isGuestLogin           = $salesChannelContext->getCustomer()->getGuest();
        $templateVariables      = new CheckoutTemplateCustomData();

        if ($selectedPaymentGateway->getHandlerIdentifier() == CreditCard::class) {
            $vaultedShopperEnable = $this->blueSnapConfig->getConfig('vaultedShopper', $salesChannelId);
            $threeDS              = $this->blueSnapConfig->getConfig('threeDS', $salesChannelId);
            $customerId           = $salesChannelContext->getCustomer()->getId();

            $queryParam         = [];
            $isCardSaved        = false;
            $shopperName        = '';
            $shopperLastName    = '';
            $shopperLast4Digits = '';
            $shopperCardType    = '';
            $vaultedShopperId   = '';

            if ($vaultedShopperEnable) {
                $isCardSaved      = $this->vaultedShopperService->vaultedShopperExist($context, $customerId);
                $vaultedShopperId = $this->vaultedShopperService->getVaultedShopperIdByCustomerId($context, $customerId);
                if ($vaultedShopperId) {
                    $queryParam['shopperId'] = $vaultedShopperId;
                    $shopperData             = $this->blueSnapClient->getVaultedShopper($vaultedShopperId, $salesChannelId);
                    $decodedData             = json_decode($shopperData, true);
                    $shopperName             = $decodedData['paymentSources']['creditCardInfo'][0]['billingContactInfo']['firstName']  ?? '';
                    $shopperLastName         = $decodedData['paymentSources']['creditCardInfo'][0]['billingContactInfo']['lastName']   ?? '';
                    $shopperLast4Digits      = $decodedData['paymentSources']['creditCardInfo'][0]['creditCard']['cardLastFourDigits'] ?? '';
                    $shopperCardType         = $decodedData['paymentSources']['creditCardInfo'][0]['creditCard']['cardType']           ?? '';
                }
            }


            $pfToken = $this->blueSnapClient->makeTokenRequest($queryParam, $salesChannelId);
            $templateVariables->assign([
              'template'             => '@Storefront/bluesnap/credit-card.html.twig',
              'isGuestLogin'         => $isGuestLogin,
              'vaultedShopperEnable' => $vaultedShopperEnable,
              'pfToken'              => $pfToken,
              'gateway'              => 'creditCard',
              'isSavedCard'          => $isCardSaved,
              'vaultedShopperId'     => $vaultedShopperId,
              'securedAmount'        => $pageObject->getCart()->getPrice()->getTotalPrice(),
              'securedCurrency'      => $salesChannelContext->getCurrency()->getIsoCode(),
              'securedFirstName'     => $salesChannelContext->getCustomer()->getFirstName(),
              'securedLastName'      => $salesChannelContext->getCustomer()->getLastName(),
              'shopperName'          => $shopperName,
              'shopperLastName'      => $shopperLastName,
              'shopperLast4Digits'   => $shopperLast4Digits,
              'shopperCardType'      => $shopperCardType,
              'threeDS'              => $threeDS,
              'js_link'              => $this->blueSnapConfig->getConfig('mode', $salesChannelId) === 'live' ? EnvironmentUrl::BLUESNAP_JS_LIVE->value : EnvironmentUrl::BLUESNAP_JS_SANDBOX->value,
            ]);

            $pageObject->addExtension(
                CheckoutTemplateCustomData::EXTENSION_NAME,
                $templateVariables
            );
        } elseif ($selectedPaymentGateway->getHandlerIdentifier() == GooglePay::class) {
            $pfToken = $this->blueSnapClient->makeTokenRequest([], $salesChannelId);
            $templateVariables->assign([
              'template'         => '@Storefront/bluesnap/google-pay.html.twig',
              'isGuestLogin'     => $isGuestLogin,
              'pfToken'          => $pfToken,
              'merchantId'       => $this->blueSnapConfig->getConfig('merchantId', $salesChannelId),
              'googleMerchantId' => $this->blueSnapConfig->getConfig('merchantGoogleId', $salesChannelId),
              'mode'             => $this->blueSnapConfig->getConfig('mode', $salesChannelId),
              'gateway'          => 'googlePay',
            ]);
            $pageObject->addExtension(
                CheckoutTemplateCustomData::EXTENSION_NAME,
                $templateVariables
            );
        } elseif ($selectedPaymentGateway->getHandlerIdentifier() == ApplePay::class) {
            $pfToken = $this->blueSnapClient->makeTokenRequest([], $salesChannelId);
            $templateVariables->assign([
              'template'     => '@Storefront/bluesnap/apple-pay.html.twig',
              'isGuestLogin' => $isGuestLogin,
              'pfToken'      => $pfToken,
              'merchantId'   => $this->blueSnapConfig->getConfig('merchantId', $salesChannelId),
              'gateway'      => 'applePay',
            ]);
            $pageObject->addExtension(
                CheckoutTemplateCustomData::EXTENSION_NAME,
                $templateVariables
            );
        }

        // Remove Payment Link on storefront
        $paymentMethods         = $pageObject->getPaymentMethods();
        $filteredPaymentMethods = $paymentMethods->filter(function (PaymentMethodEntity $paymentMethod) {
            return $paymentMethod->getHandlerIdentifier() !== LinkPayment::class;
        });
        $pageObject->setPaymentMethods($filteredPaymentMethods);
    }
}
