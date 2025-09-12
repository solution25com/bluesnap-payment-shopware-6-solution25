<?php

namespace solu1BluesnapPayment\Core\Content\BlueSnap;

use solu1BluesnapPayment\Core\Content\BlueSnap\SalesChannel\BlueSnapApiResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

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

  abstract public function hostedPagesLink(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

  abstract public function createTransaction(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

  abstract public function refund(Request $request, Context $context): BlueSnapApiResponse;

  abstract public function handlePayment(Request $request, SalesChannelContext $context): BlueSnapApiResponse;

  abstract public function reSendPaymentLink(Request $request, Context $context): BlueSnapApiResponse;

}
