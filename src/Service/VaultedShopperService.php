<?php

declare(strict_types=1);

namespace BlueSnap\Service;

use BlueSnap\Core\Content\VaultedShopper\VaultedShopperEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class VaultedShopperService
{
    private EntityRepository $vaultedShopperRepository;
    private LoggerInterface $logger;

    public function __construct(EntityRepository $vaultedShopperRepository, LoggerInterface $logger)
    {
        $this->vaultedShopperRepository = $vaultedShopperRepository;
        $this->logger = $logger;
    }

    public function store(string $vaultedShopperId, ?string $cardType, string $customerId, Context $context): void
    {
        try {
            $existingShopper = $this->getVaultedShopper($context, $customerId);

            if ($existingShopper) {
                if ($existingShopper->getVaultedShopperId() === $vaultedShopperId) {
                    return;
                }
                $this->logger->warning('BlueSnap returned a different vaulted shopper for an existing customer', [
                    'customerId' => $customerId,
                    'previousVaultedShopperId' => $existingShopper->getVaultedShopperId(),
                    'newVaultedShopperId' => $vaultedShopperId,
                ]);

                $this->vaultedShopperRepository->update(
                    [
                        [
                            'id' => $existingShopper->getId(),
                            'vaultedShopperId' => $vaultedShopperId,
                            'preferredCardType' => null,
                            'preferredCardLastFour' => null,
                        ],
                    ],
                    $context
                );

                return;
            }

            $this->vaultedShopperRepository->create(
                [
                    [
                        'id' => Uuid::randomHex(),
                        'customerId' => $customerId,
                        'vaultedShopperId' => $vaultedShopperId,
                        'cardType' => $cardType,
                    ],
                ],
                $context
            );
        } catch (\Exception $e) {
            $this->logger->error('Error storing vaulted shopper data: ' . $e->getMessage());
        }
    }

    public function getVaultedShopper(Context $context, string $customerId): ?VaultedShopperEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->setLimit(1);

        /** @var VaultedShopperEntity|null $vaultedShopper */
        /* @phpstan-ignore-next-line Shopware 6.8 EntitySearchResult hierarchy change */
        $vaultedShopper = $this->vaultedShopperRepository->search($criteria, $context)->first();

        return $vaultedShopper;
    }

    public function getVaultedShopperIdByCustomerId(Context $context, string $customerId): ?string
    {
        return $this->getVaultedShopper($context, $customerId)?->getVaultedShopperId();
    }

    public function vaultedShopperExist(Context $context, string $customerId): bool
    {
        return $this->getVaultedShopper($context, $customerId) !== null;
    }

    public function setPreferredCard(Context $context, string $customerId, ?string $cardType, ?string $lastFourDigits): void
    {
        $vaultedShopper = $this->getVaultedShopper($context, $customerId);
        if (!$vaultedShopper) {
            return;
        }

        try {
            $this->vaultedShopperRepository->update(
                [
                    [
                        'id' => $vaultedShopper->getId(),
                        'preferredCardType' => $cardType !== null ? strtoupper($cardType) : null,
                        'preferredCardLastFour' => $lastFourDigits,
                    ],
                ],
                $context
            );
        } catch (\Exception $e) {
            $this->logger->error('Error storing preferred BlueSnap card: ' . $e->getMessage());
        }
    }
}
