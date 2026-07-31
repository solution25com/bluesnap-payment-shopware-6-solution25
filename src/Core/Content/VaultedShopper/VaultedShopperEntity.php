<?php

declare(strict_types=1);

namespace BlueSnap\Core\Content\VaultedShopper;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Swag\PayPal\RestApi\V2\Api\Order\PaymentSource\Common\Attributes\Customer;
use Symfony\Component\String\ByteString;

class VaultedShopperEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $customerId;

    protected string $vaultedShopperId;
    protected string $cardType;
    protected ?CustomerEntity $customer;

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

    public function setCustomer(CustomerEntity $customer): void
    {
        $this->customer = $customer;
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
