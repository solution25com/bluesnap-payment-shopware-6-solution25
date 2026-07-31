<?php

declare(strict_types=1);

namespace BlueSnap\Storefront\Controller;

use BlueSnap\Core\Content\BlueSnap\SalesChannel\BlueSnapApiResponse;
use BlueSnap\Core\Content\BlueSnap\SalesChannel\BlueSnapRoute;
use Shopware\Core\Framework\Context;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class BlueSnapApiController extends StorefrontController
{
    private BlueSnapRoute $route;

    public function __construct(BlueSnapRoute $route)
    {
        $this->route = $route;
    }

    #[Route(path: '/api/refund', name: 'api.bluesnap.refund', methods: ['POST'])]
    public function refund(Request $request, Context $context): BlueSnapApiResponse
    {
        return $this->route->refund($request, $context);
    }

    #[Route(path: '/api/re-send-payment-link', name: 'api.bluesnap.reSendPaymentLink', methods: ['POST'])]
    public function reSendPaymentLink(Request $request, Context $context)
    {
        return $this->route->reSendPaymentLink($request, $context);
    }
}
