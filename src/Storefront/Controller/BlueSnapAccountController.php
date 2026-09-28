<?php

declare(strict_types=1);

namespace BlueSnap\Storefront\Controller;

use BlueSnap\Library\Constants\EnvironmentUrl;
use BlueSnap\Service\BlueSnapConfig;
use BlueSnap\Service\SavedCardService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class BlueSnapAccountController extends StorefrontController
{
    private GenericPageLoaderInterface $genericPageLoader;
    private SavedCardService $savedCardService;
    private BlueSnapConfig $blueSnapConfig;

    public function __construct(
        GenericPageLoaderInterface $genericPageLoader,
        SavedCardService $savedCardService,
        BlueSnapConfig $blueSnapConfig
    ) {
        $this->genericPageLoader = $genericPageLoader;
        $this->savedCardService = $savedCardService;
        $this->blueSnapConfig = $blueSnapConfig;
    }

    #[Route(
        path: '/account/bluesnap/saved-cards',
        name: 'frontend.bluesnap.savedCards',
        defaults: ['_loginRequired' => true, '_loginRequiredAllowGuest' => false, '_noStore' => true],
        methods: ['GET']
    )]
    public function savedCards(Request $request, SalesChannelContext $context): Response
    {
        $customer = $context->getCustomer();
        if ($customer === null || $customer->getGuest()) {
            return $this->redirectToRoute('frontend.account.login.page');
        }

        $salesChannelId = $context->getSalesChannelId();

        if (!$this->savedCardService->isEnabled($salesChannelId)) {
            return $this->redirectToRoute('frontend.account.home.page');
        }

        return $this->renderStorefront('@BlueSnap/storefront/page/account/bluesnap/saved-cards.html.twig', [
            'page' => $this->genericPageLoader->load($request, $context),
            'bluesnapCards' => $this->savedCardService->getCards($customer, $salesChannelId, $context->getContext()),
            'bluesnapCustomerName' => trim($customer->getFirstName() . ' ' . $customer->getLastName()),
            'bluesnapScriptUrl' => $this->blueSnapConfig->getConfig('mode', $salesChannelId) === 'live'
                ? EnvironmentUrl::BLUESNAP_JS_LIVE->value
                : EnvironmentUrl::BLUESNAP_JS_SANDBOX->value,
        ]);
    }
}
