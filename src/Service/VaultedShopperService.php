<?php

namespace BlueSnap\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class VaultedShopperService
{
    private EntityRepository $vaultedShopperRepository;
    private LoggerInterface $logger;

    public function __construct(EntityRepository $vaultedShopperRepository, LoggerInterface $logger)
    {
        $this->vaultedShopperRepository = $vaultedShopperRepository;
        $this->logger = $logger;
    }

    public function store(string $vaultedShopperId, string $cardType, string $customerId, Context $context): void
    {
        try {
            $existingShopper = $this->vaultedShopperRepository->search(
                (new Criteria())->addFilter(new EqualsFilter('customerId', $customerId)),
                $context
            )->first();
            if ($existingShopper) {
                $this->vaultedShopperRepository->upsert(
                    [
                        [
                            'id' => $existingShopper->getId(),
                            'customerId' => $customerId,
                            'vaultedShopperId' => $vaultedShopperId,
                            'cardType' => $cardType,
                            'updatedAt' => (new \DateTime())->format('Y-m-d H:i:s'),
                        ]
                    ],
                    $context
                );
            } else {
                $this->vaultedShopperRepository->upsert(
                    [
                        [
                            'id' => Uuid::randomHex(),
                            'customerId' => $customerId,
                            'vaultedShopperId' => $vaultedShopperId,
                            'cardType' => $cardType,
                            'createdAt' => (new \DateTime())->format('Y-m-d H:i:s'),
                        ]
                    ],
                    $context
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Error storing vaulted shopper data: ' . $e->getMessage());
        }
    }

    public function getVaultedShopperIdByCustomerId(Context $context, string $customerId): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $res = $this->vaultedShopperRepository->search($criteria, $context)->first();
        return $res ? $res->getVaultedShopperId() : null;
    }

    public function vaultedShopperExist(Context $context, string $customerId): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $vaultedShopper = $this->vaultedShopperRepository->search($criteria, $context)->first();
        return $vaultedShopper !== null;
    }
}
