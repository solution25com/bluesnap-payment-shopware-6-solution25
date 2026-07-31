<?php

declare(strict_types=1);

namespace BlueSnap\Core\Checkout\Cart;

use BlueSnap\Service\BlueSnapApiClient;
use BlueSnap\Service\BlueSnapConfig;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;
use BlueSnap\Service\VaultedShopperService;
use BlueSnap\Gateways\CreditCard;

class BlueSnapSurchargeCartProcessor implements CartDataCollectorInterface, CartProcessorInterface
{
    public const SURCHARGE_LINE_ITEM_TYPE = 'bluesnap_surcharge';
    public const SURCHARGE_LINE_ITEM_ID = 'bluesnap-surcharge';
    private const DATA_KEY = 'bluesnap_surcharge_calculation';

    public function __construct(
        private readonly BlueSnapApiClient $blueSnapApiClient,
        private readonly BlueSnapConfig $blueSnapConfig,
        private readonly BlueSnapSurchargeContext $surchargeContext,
        private readonly RequestStack $requestStack,
        private readonly VaultedShopperService $vaultedShopperService
    ) {
    }

    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        if ($data->has(self::DATA_KEY)) {
            return;
        }

        $salesChannelId = $context->getSalesChannelId();

        if (!$this->blueSnapConfig->getConfig('useSurcharge', $salesChannelId)) {
            return;
        }

        if (!$this->shouldApplySurchargeForCurrentRoute()) {
            return;
        }

        $existingSurchargeLineItem = $original->getLineItems()->get(self::SURCHARGE_LINE_ITEM_ID);
        $existingSurchargeAmount = $existingSurchargeLineItem !== null ? $existingSurchargeLineItem->getPrice()->getTotalPrice() : 0.0;
        $baseAmount = round($original->getPrice()->getTotalPrice() - $existingSurchargeAmount, 2);
        if ($baseAmount <= 0) {
            return;
        }

        $customer = $context->getCustomer();
        if ($customer && $context->getPaymentMethod()->getHandlerIdentifier() === CreditCard::class && $this->blueSnapConfig->getConfig('vaultedShopper', $salesChannelId) && !$this->surchargeContext->getVaultedCustomerId() && !$this->surchargeContext->getPfToken()) {
            $vaultedShopperId = $this->vaultedShopperService->getVaultedShopperIdByCustomerId(
                $context->getContext(),
                $customer->getId()
            );
            if ($vaultedShopperId) {
                $this->surchargeContext->setVaultedCustomerId($vaultedShopperId);
            }
        }

        $vaultedShopperId = $this->surchargeContext->getVaultedCustomerId();
        $pfToken = $this->surchargeContext->getPfToken();

        if (!$vaultedShopperId && !$pfToken) {
            return;
        }

        $existingSurcharge = $this->surchargeContext->getSurchargeData();
        if (
            is_array($existingSurcharge)
            && ($existingSurcharge['bluesnap_surcharge_base_amount'] ?? 0) === $baseAmount
            && ($existingSurcharge['bluesnap_surcharge_pfToken'] ?? '') === ($pfToken ?? '')
        ) {
            $amount = (float)$existingSurcharge['bluesnap_surcharge_amount'];
            $token = (string)$existingSurcharge['bluesnap_surcharge_token'];
            $reference = $existingSurcharge['bluesnap_surcharge_reference'] ?? null;
        } else {
            $body = [
                'currency' => $context->getCurrency()->getIsoCode(),
                'amount' => (string)$baseAmount,
                'paymentMethod' => 'CC',
            ];

            if ($vaultedShopperId) {
                $body['vaultedShopperId'] = $vaultedShopperId;
            } elseif ($pfToken) {
                $body['pfToken'] = $pfToken;
            }

            $res = $this->blueSnapApiClient->calculateSurcharge($body, $salesChannelId);
            $decoded = is_string($res) ? json_decode($res, true) : $res;
            if (!is_array($decoded)) {
                return;
            }

            $info = $decoded['surchargeInfo'] ?? $decoded;
            $amount = (float)($info['surchargeAmount'] ?? 0);
            $token = (string)($info['surchargeToken'] ?? '');
            $reference = $info['surchargeReference'] ?? null;

            if ($amount <= 0 || $token === '') {
                return;
            }

            $this->surchargeContext->setSurchargeData([
                'bluesnap_surcharge_amount' => $amount,
                'bluesnap_surcharge_token' => $token,
                'bluesnap_surcharge_reference' => $reference,
                'bluesnap_surcharge_base_amount' => $baseAmount,
                'bluesnap_surcharge_pfToken' => $pfToken,
            ]);
        }

        $data->set(self::DATA_KEY, [
            'amount' => $amount,
            'token' => $token,
            'reference' => $reference,
            'pfToken' => $pfToken,
        ]);
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        if ($original->has(self::SURCHARGE_LINE_ITEM_ID)) {
            $original->remove(self::SURCHARGE_LINE_ITEM_ID);
        }

        $calculation = $data->get(self::DATA_KEY);
        if (!is_array($calculation)) {
            return;
        }

        $amount = (float) $calculation['amount'];
        $token = (string) $calculation['token'];
        $reference = $calculation['reference'] ?? null;
        $pfToken = $calculation['pfToken'] ?? null;

        $lineItem = new LineItem(self::SURCHARGE_LINE_ITEM_ID, self::SURCHARGE_LINE_ITEM_TYPE, null, 1);
        $lineItem->setLabel('Payment Surcharge');
        $lineItem->setGood(false);
        $lineItem->setStackable(false);
        $lineItem->setRemovable(true);
        $lineItem->setPrice(new CalculatedPrice(
            $amount,
            $amount,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            1
        ));
        $lineItem->setPayload([
            'bluesnap_surcharge_token' => $token,
            'bluesnap_surcharge_amount' => $amount,
            'bluesnap_surcharge_reference' => $reference,
            'bluesnap_surcharge_pfToken' => $pfToken,
        ]);

        $toCalculate->add($lineItem);
        $toCalculate->getPrice()->addExtension('bluesnap_surcharge', new ArrayStruct([
            'token' => $token,
            'amount' => $amount,
            'reference' => $reference,
            'pfToken' => $pfToken,
        ]));

        $toCalculate->markModified();
    }

    private function shouldApplySurchargeForCurrentRoute(): bool
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return true;
        }

        $route = (string) $request->attributes->get('_route', '');
        if ($route === '') {
            return true;
        }

        if (str_starts_with($route, 'frontend.checkout.') || str_starts_with($route, 'store-api.checkout.')) {
            return true;
        }

        return false;
    }
}
