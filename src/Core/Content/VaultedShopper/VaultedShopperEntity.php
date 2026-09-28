<?php

declare(strict_types=1);

namespace BlueSnap\Core\Content\VaultedShopper;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class VaultedShopperEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $customerId = null;

    protected string $vaultedShopperId;

    protected ?string $cardType = null;

    protected ?string $preferredCardType = null;

    protected ?string $preferredCardLastFour = null;

    protected ?CustomerEntity $customer = null;

    public function getCustomerId(): ?string
    {
        return $this->customerId;
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerEntity $customer): void
    {
        $this->customer = $customer;
    }

    public function getVaultedShopperId(): string
    {
        return $this->vaultedShopperId;
    }

    public function setVaultedShopperId(string $vaultedShopperId): void
    {
        $this->vaultedShopperId = $vaultedShopperId;
    }

    public function getCardType(): ?string
    {
        return $this->cardType;
    }

    public function setCardType(?string $cardType): void
    {
        $this->cardType = $cardType;
    }

    public function getPreferredCardType(): ?string
    {
        return $this->preferredCardType;
    }

    public function setPreferredCardType(?string $preferredCardType): void
    {
        $this->preferredCardType = $preferredCardType;
    }

    public function getPreferredCardLastFour(): ?string
    {
        return $this->preferredCardLastFour;
    }

    public function setPreferredCardLastFour(?string $preferredCardLastFour): void
    {
        $this->preferredCardLastFour = $preferredCardLastFour;
    }
}
