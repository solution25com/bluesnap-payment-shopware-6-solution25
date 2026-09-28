<?php

namespace BlueSnap\Core\Content\BlueSnap;

use BlueSnap\Core\Content\BlueSnap\SalesChannel\BlueSnapApiResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Checkout\Payment\SalesChannel\HandlePaymentMethodRouteResponse;

abstract class AbstractBlueSnapRoute
{
    abstract public function getDecorated(): AbstractBlueSnapRoute;

    abstract public function capture(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function googleCapture(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function appleCapture(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function appleCreateWallet(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function getBluesnapConfig(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function vaultedShopperData(string $vaultedShopperId, Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function vaultedShopper(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function updateVaultedShopper(string $vaultedShopperId, Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function listSavedCards(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function createSavedCardToken(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function addSavedCard(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function removeSavedCard(string $cardKey, Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function setPreferredSavedCard(string $cardKey, Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function selectSavedCard(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function hostedPagesLink(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function createTransaction(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function refund(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function adminRefund(Request $request, Context $context): BlueSnapApiResponse;

    abstract public function handlePayment(Request $request, SalesChannelContext $context): BlueSnapApiResponse|HandlePaymentMethodRouteResponse;

    abstract public function reSendPaymentLink(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

    abstract public function adminReSendPaymentLink(Request $request, Context $context): BlueSnapApiResponse;
}
