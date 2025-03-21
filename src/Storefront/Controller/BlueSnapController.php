<?php

declare(strict_types=1);

namespace BlueSnap\Storefront\Controller;

use BlueSnap\Core\Content\BlueSnap\SalesChannel\BlueSnapApiResponse;
use BlueSnap\Core\Content\BlueSnap\SalesChannel\BlueSnapRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Checkout\Cart\Cart;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class BlueSnapController extends StorefrontController
{
    private BlueSnapRoute $route;

    public function __construct(BlueSnapRoute $route)
    {
        $this->route = $route;
    }

    #[Route(path: '/apple-create-wallet', name: 'frontend.bluesnap.apple-create-wallet', methods: ['POST'])]
    public function appleCreateWallet(Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        return $this->route->appleCreateWallet($request, $context);
    }

    #[Route(path: '/apple-capture', name: 'frontend.bluesnap.apple-capture', methods: ['POST'])]
    public function appleCapture(Cart $cart, Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $price = $cart->getPrice()->getTotalPrice();
        $request->request->set('amount', (string)$price);
        return $this->route->appleCapture($request, $context);
    }

    #[Route(path: '/capture', name: 'frontend.bluesnap.capture', methods: ['POST'])]
    public function capture(Cart $cart, Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $price = $cart->getPrice()->getTotalPrice();
        $request->request->set('amount', (string)$price);
        return $this->route->capture($request, $context);
    }

    #[Route(path: '/google-capture', name: 'frontend.bluesnap.googleCapture', methods: ['POST'])]
    public function googleCapture(Cart $cart, Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $price = $cart->getPrice()->getTotalPrice();
        $request->request->set('amount', (string)$price);
        return $this->route->googleCapture($request, $context);
    }

    #[Route(path: '/vaulted-shopper', name: 'frontend.bluesnap.vaultedShopper', methods: ['POST'])]
    public function vaultedShopper(Cart $cart, Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        $price = $cart->getPrice()->getTotalPrice();
        $request->request->set('amount', (string)$price);
        return $this->route->vaultedShopper($request, $context);
    }

    #[Route(path: '/vaulted-shopper-data/{vaultedShopperId}', name: 'frontend.bluesnap.vaultedShopperData', methods: ['GET'])]
    public function vaultedShopperData(string $vaultedShopperId, Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        return $this->route->vaultedShopperData($vaultedShopperId, $request, $context);
    }

    #[Route(path: '/update-vaulted-shopper/{vaultedShopperId}', name: 'frontend.bluesnap.updateVaultedShopper', methods: ['PUT'])]
    public function updateVaultedShopper(string $vaultedShopperId, Request $request, SalesChannelContext $context): BlueSnapApiResponse
    {
        return $this->route->updateVaultedShopper($vaultedShopperId, $request, $context);
    }

    #[Route(path: '/payment-link-success', name: 'frontend.bluesnap.paymentLinkSuccessPage', methods: ['GET'])]
    public function paymentLinkSuccessPage(Request $request, SalesChannelContext $context): Response
    {
        return $this->renderStorefront('@BlueSnap/storefront/page/paymentLinkSuccess.html.twig');
    }

    #[Route(path: '/payment-link-fail', name: 'frontend.bluesnap.paymentLinkFailPage', methods: ['GET'])]
    public function paymentLinkFailPage(Request $request, SalesChannelContext $context): Response
    {
        return $this->renderStorefront('@BlueSnap/storefront/page/paymentLinkFail.html.twig');
    }
}
