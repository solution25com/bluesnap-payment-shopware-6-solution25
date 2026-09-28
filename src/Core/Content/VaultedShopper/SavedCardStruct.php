<?php

declare(strict_types=1);

namespace BlueSnap\Core\Content\VaultedShopper;

use Shopware\Core\Framework\Struct\Struct;

class SavedCardStruct extends Struct
{
    protected string $cardKey;
    protected string $cardType;
    protected string $lastFourDigits;
    protected ?string $expirationMonth;
    protected ?string $expirationYear;
    protected string $expirationDate;
    protected ?string $cardHolderFirstName;
    protected ?string $cardHolderLastName;
    protected bool $preferred;
    protected bool $expired;

    public function __construct(
        string $cardType,
        string $lastFourDigits,
        ?string $expirationMonth = null,
        ?string $expirationYear = null,
        ?string $cardHolderFirstName = null,
        ?string $cardHolderLastName = null,
        bool $preferred = false
    ) {
        $this->cardType = strtoupper($cardType);
        $this->lastFourDigits = $lastFourDigits;
        $this->expirationMonth = $expirationMonth !== null && $expirationMonth !== '' ? str_pad($expirationMonth, 2, '0', STR_PAD_LEFT) : null;
        $this->expirationYear = $expirationYear !== null && $expirationYear !== '' ? $expirationYear : null;
        $this->cardHolderFirstName = $cardHolderFirstName;
        $this->cardHolderLastName = $cardHolderLastName;
        $this->preferred = $preferred;
        $this->cardKey = self::createCardKey($this->cardType, $this->lastFourDigits);
        $this->expirationDate = $this->buildExpirationDate();
        $this->expired = $this->calculateExpired();
    }

    /**
     * @param array<string, mixed> $creditCardInfo A single entry of paymentSources.creditCardInfo
     */
    public static function fromVaultedShopperCard(array $creditCardInfo, bool $preferred = false): ?self
    {
        $creditCard = $creditCardInfo['creditCard'] ?? null;
        if (!\is_array($creditCard)) {
            return null;
        }

        $cardType = (string) ($creditCard['cardType'] ?? '');
        $lastFour = (string) ($creditCard['cardLastFourDigits'] ?? '');

        if ($cardType === '' || $lastFour === '') {
            return null;
        }

        $billing = $creditCardInfo['billingContactInfo'] ?? [];

        return new self(
            $cardType,
            $lastFour,
            isset($creditCard['expirationMonth']) ? (string) $creditCard['expirationMonth'] : null,
            isset($creditCard['expirationYear']) ? (string) $creditCard['expirationYear'] : null,
            \is_array($billing) && isset($billing['firstName']) ? (string) $billing['firstName'] : null,
            \is_array($billing) && isset($billing['lastName']) ? (string) $billing['lastName'] : null,
            $preferred
        );
    }

    public static function createCardKey(string $cardType, string $lastFourDigits): string
    {
        return substr(hash('sha256', strtoupper($cardType) . '|' . $lastFourDigits), 0, 32);
    }

    public function getCardKey(): string
    {
        return $this->cardKey;
    }

    public function getCardType(): string
    {
        return $this->cardType;
    }

    public function getLastFourDigits(): string
    {
        return $this->lastFourDigits;
    }

    public function getExpirationMonth(): ?string
    {
        return $this->expirationMonth;
    }

    public function getExpirationYear(): ?string
    {
        return $this->expirationYear;
    }

    public function getExpirationDate(): string
    {
        return $this->expirationDate;
    }

    public function getCardHolderFirstName(): ?string
    {
        return $this->cardHolderFirstName;
    }

    public function getCardHolderLastName(): ?string
    {
        return $this->cardHolderLastName;
    }

    public function getCardHolderName(): string
    {
        return trim(($this->cardHolderFirstName ?? '') . ' ' . ($this->cardHolderLastName ?? ''));
    }

    public function isPreferred(): bool
    {
        return $this->preferred;
    }

    public function setPreferred(bool $preferred): void
    {
        $this->preferred = $preferred;
    }

    public function isExpired(): bool
    {
        return $this->expired;
    }

    private function buildExpirationDate(): string
    {
        if ($this->expirationMonth === null || $this->expirationYear === null) {
            return '';
        }

        return $this->expirationMonth . '/' . $this->expirationYear;
    }

    private function calculateExpired(): bool
    {
        if ($this->expirationMonth === null || $this->expirationYear === null) {
            return false;
        }

        $month = (int) $this->expirationMonth;
        $year = (int) $this->expirationYear;

        if ($month < 1 || $month > 12 || $year < 1000) {
            return false;
        }

        $now = new \DateTimeImmutable('now');

        return $year < (int) $now->format('Y')
            || ($year === (int) $now->format('Y') && $month < (int) $now->format('n'));
    }
}
