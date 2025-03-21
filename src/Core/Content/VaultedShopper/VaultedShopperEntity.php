<?php

declare(strict_types=1);

namespace BlueSnap\Core\Content\VaultedShopper;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Symfony\Component\String\ByteString;

class VaultedShopperEntity extends Entity
{
    use EntityIdTrait;

    protected $id;

    protected ?string $customerId;

    protected $vaultedShopperId;

    protected $cardType;

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getCustomerId(): ?string
    {
        return $this->customerId;
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getVaultedShopperId()
    {
        return $this->vaultedShopperId;
    }

    public function setVaultedShopperId(string $vaultedShopperId): void
    {
        $this->vaultedShopperId = $vaultedShopperId;
    }

    public function getCardType()
    {
        return $this->cardType;
    }
    public function setCardType(string $cardType): void
    {
        $this->cardType = $cardType;
    }
}
