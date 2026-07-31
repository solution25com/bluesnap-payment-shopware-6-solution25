<?php

declare(strict_types=1);

namespace BlueSnap\Storefront\Controller;

use BlueSnap\Service\BlueSnapApiClient;
use Shopware\Core\Framework\Context;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class TestApiConnectionController extends StorefrontController
{
    private BlueSnapApiClient $bluesnap;


    public function __construct(BlueSnapApiClient $bluesnap)
    {
        $this->bluesnap = $bluesnap;
    }


    #[Route(path: '/api/_action/bluesnap-test-connection/test-connection', name: 'api.action.bluesnap.test-connection', methods: ['POST'])]
    public function testConnection(Request $request, Context $context): Response
    {
        $salesChannelId = $request->get('salesChannelId') ?? '';
        $result = $this->bluesnap->testConnection($salesChannelId);

        return new JsonResponse(['success' => $result]);
    }
}
