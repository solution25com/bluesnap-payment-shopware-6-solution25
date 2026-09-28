<?php

declare(strict_types=1);

namespace BlueSnap\EventSubscriber;

use BlueSnap\Core\Checkout\Cart\BlueSnapSurchargeCartProcessor;
use BlueSnap\Service\OrderSurchargeService;
use BlueSnap\Gateways\CreditCard;
use BlueSnap\Library\Constants\EnvironmentUrl;
use BlueSnap\Service\BlueSnapApiClient;
use BlueSnap\Service\BlueSnapConfig;
use BlueSnap\Service\SavedCardService;
use BlueSnap\Service\VaultedShopperService;
use BlueSnap\Storefront\Struct\CheckoutTemplateCustomData;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AccountEditOrderEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BlueSnapApiClient $blueSnapClient,
        private readonly BlueSnapConfig $blueSnapConfig,
        private readonly VaultedShopperService $vaultedShopperService,
        private readonly SavedCardService $savedCardService
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AccountEditOrderPageLoadedEvent::class => 'addCreditCardFormFields',
        ];
    }

    public function addCreditCardFormFields(AccountEditOrderPageLoadedEvent $event): void
    {
        if ($event->getSalesChannelContext()->getPaymentMethod()->getHandlerIdentifier() !== CreditCard::class) {
            return;
        }

        if (!$event->getPage()->isPaymentChangeable()) {
            return;
        }

        $templateVariables = new CheckoutTemplateCustomData();
        $templateVariables->assign($this->getCreditCardPageFields($event));

        $event->getPage()->addExtension(CheckoutTemplateCustomData::EXTENSION_NAME, $templateVariables);
    }

    /**
     * @return array<string, mixed>
     */
    private function getCreditCardPageFields(AccountEditOrderPageLoadedEvent $event): array
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $salesChannelId = $salesChannelContext->getSalesChannelId();
        $customer = $salesChannelContext->getCustomer();
        $order = $event->getPage()->getOrder();

        $queryParam = [];
        $savedCards = [];
        $preferredCardKey = '';
        $vaultedShopperId = '';

        $vaultedShopperEnable = $this->blueSnapConfig->getConfig('vaultedShopper', $salesChannelId);
        if ($vaultedShopperEnable && $customer !== null) {
            $vaultedShopperId = (string) ($this->vaultedShopperService->getVaultedShopperIdByCustomerId(
                $event->getContext(),
                $customer->getId()
            ) ?? '');

            if ($vaultedShopperId !== '') {
                $queryParam['shopperId'] = $vaultedShopperId;
                $savedCards = $this->savedCardService->getCards($customer, $salesChannelId, $event->getContext());

                foreach ($savedCards as $savedCard) {
                    if ($savedCard->isPreferred()) {
                        $preferredCardKey = $savedCard->getCardKey();
                        break;
                    }
                }
            }
        }

        $pfTokenResponse = $this->blueSnapClient->makeTokenRequest($queryParam, $salesChannelId);
        if (\is_array($pfTokenResponse) && $queryParam !== []) {
            $pfTokenResponse = $this->blueSnapClient->makeTokenRequest([], $salesChannelId);
        }
        $pfToken = \is_array($pfTokenResponse) ? null : $pfTokenResponse;

        return [
            'template' => '@Storefront/bluesnap/credit-card.html.twig',
            'gateway' => 'creditCard',
            'isGuestLogin' => $customer?->getGuest() ?? true,
            'flow' => 'order_payment',
            'orderId' => $order->getId(),
            'vaultedShopperEnable' => $vaultedShopperEnable,
            'pfToken' => $pfToken,
            'vaultedShopperId' => $vaultedShopperId,
            'savedCards' => $savedCards,
            'hasSavedCards' => $savedCards !== [],
            'preferredCardKey' => $preferredCardKey,
            'selectedCardKey' => $preferredCardKey,
            'securedAmount' => $this->getBaseAmount($order),
            'securedCurrency' => $order->getCurrency()?->getIsoCode() ?? $salesChannelContext->getCurrency()->getIsoCode(),
            'securedFirstName' => $customer?->getFirstName() ?? '',
            'securedLastName' => $customer?->getLastName() ?? '',
            'isSurchargeActive' => (bool) $this->blueSnapConfig->getConfig('useSurcharge', $salesChannelId),
            'surchargeToken' => '',
            'surchargeAmount' => '',
            'threeDS' => $this->blueSnapConfig->getConfig('threeDS', $salesChannelId),
            'js_link' => $this->blueSnapConfig->getConfig('mode', $salesChannelId) === 'live'
                ? EnvironmentUrl::BLUESNAP_JS_LIVE->value
                : EnvironmentUrl::BLUESNAP_JS_SANDBOX->value,
        ];
    }

    private function getBaseAmount(OrderEntity $order): float
    {
        $surcharge = 0.0;

        foreach ($order->getLineItems() ?? [] as $lineItem) {
            if (OrderSurchargeService::isSurchargeLineItem($lineItem->getType(), $lineItem->getIdentifier())) {
                $surcharge += $lineItem->getTotalPrice();
            }
        }

        return round($order->getPrice()->getTotalPrice() - $surcharge, 2);
    }
}
