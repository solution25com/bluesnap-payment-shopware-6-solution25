<?php

namespace BlueSnap\Core\Content\PaymentLink;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class PaymentLinkEntity extends Entity
{
    use EntityIdTrait;

    protected $id;

    protected string $order_id;

    protected string $link;

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getOrderId(): string
    {
        return $this->order_id;
    }

    public function setOrderId(string $orderId): void
    {
        $this->order_id = $orderId;
    }

    public function getLink(): string
    {
        return $this->link;
    }

    public function setLink(string $link): void
    {
        $this->link = $link;
    }
}
