<?php

declare(strict_types=1);

namespace BlueSnap\Service;

use BlueSnap\Core\Content\VaultedShopper\SavedCardStruct;
use BlueSnap\Exceptions\SavedCardException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;

class SavedCardService
{
    private BlueSnapApiClient $blueSnapClient;
    private VaultedShopperService $vaultedShopperService;
    private BlueSnapConfig $blueSnapConfig;
    private LoggerInterface $logger;

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $cardCache = [];

    public function __construct(
        BlueSnapApiClient $blueSnapClient,
        VaultedShopperService $vaultedShopperService,
        BlueSnapConfig $blueSnapConfig,
        LoggerInterface $logger
    ) {
        $this->blueSnapClient = $blueSnapClient;
        $this->vaultedShopperService = $vaultedShopperService;
        $this->blueSnapConfig = $blueSnapConfig;
        $this->logger = $logger;
    }

    public function isEnabled(string $salesChannelId): bool
    {
        return (bool) $this->blueSnapConfig->getConfig('vaultedShopper', $salesChannelId);
    }

    public function isEligible(?CustomerEntity $customer): bool
    {
        return $customer !== null && !$customer->getGuest();
    }

    /**
     * @return SavedCardStruct[]
     */
    public function getCards(?CustomerEntity $customer, string $salesChannelId, Context $context): array
    {
        if (!$this->isEligible($customer) || !$this->isEnabled($salesChannelId)) {
            return [];
        }

        /** @var CustomerEntity $customer */
        $vaultedShopper = $this->vaultedShopperService->getVaultedShopper($context, $customer->getId());
        if ($vaultedShopper === null) {
            return [];
        }

        $rawCards = $this->fetchRawCards($vaultedShopper->getVaultedShopperId(), $salesChannelId);
        if ($rawCards === []) {
            return [];
        }

        $cards = [];
        foreach ($rawCards as $rawCard) {
            $card = SavedCardStruct::fromVaultedShopperCard($rawCard);
            if ($card !== null) {
                $cards[] = $card;
            }
        }

        if ($cards === []) {
            return [];
        }

        $this->applyPreference(
            $cards,
            $customer,
            $vaultedShopper->getPreferredCardType(),
            $vaultedShopper->getPreferredCardLastFour(),
            $context
        );

        return $cards;
    }

    public function getPreferredCard(?CustomerEntity $customer, string $salesChannelId, Context $context): ?SavedCardStruct
    {
        foreach ($this->getCards($customer, $salesChannelId, $context) as $card) {
            if ($card->isPreferred()) {
                return $card;
            }
        }

        return null;
    }

    public function findCardByKey(?CustomerEntity $customer, string $cardKey, string $salesChannelId, Context $context): ?SavedCardStruct
    {
        foreach ($this->getCards($customer, $salesChannelId, $context) as $card) {
            if (hash_equals($card->getCardKey(), $cardKey)) {
                return $card;
            }
        }

        return null;
    }

    /**
     * @return array{cardType: string, cardLastFourDigits: string}|null
     */
    public function getCardSelector(?CustomerEntity $customer, string $cardKey, string $salesChannelId, Context $context): ?array
    {
        $card = $this->findCardByKey($customer, $cardKey, $salesChannelId, $context);
        if ($card === null) {
            return null;
        }

        return [
            'cardType' => $card->getCardType(),
            'cardLastFourDigits' => $card->getLastFourDigits(),
        ];
    }

    public function setPreferredCard(?CustomerEntity $customer, string $cardKey, string $salesChannelId, Context $context): bool
    {
        $card = $this->findCardByKey($customer, $cardKey, $salesChannelId, $context);
        if ($card === null) {
            return false;
        }

        /** @var CustomerEntity $customer */
        $this->vaultedShopperService->setPreferredCard(
            $context,
            $customer->getId(),
            $card->getCardType(),
            $card->getLastFourDigits()
        );

        return true;
    }

    public function createAddCardToken(?CustomerEntity $customer, string $salesChannelId, Context $context): string
    {
        $this->assertEligible($customer, $salesChannelId);

        $token = $this->blueSnapClient->makeTokenRequest([], $salesChannelId);
        if (!\is_string($token) || $token === '') {
            throw new SavedCardException($this->logger, 'Could not create a BlueSnap payment field token', 502);
        }

        return $token;
    }

    public function addCard(?CustomerEntity $customer, string $pfToken, string $salesChannelId, Context $context): SavedCardStruct
    {
        $this->assertEligible($customer, $salesChannelId);

        /** @var CustomerEntity $customer */
        $vaultedShopper = $this->vaultedShopperService->getVaultedShopper($context, $customer->getId());
        $knownCardKeys = [];

        if ($vaultedShopper === null) {
            $vaultedShopperId = $this->createVaultedShopper($customer, $pfToken, $salesChannelId);
            $this->vaultedShopperService->store($vaultedShopperId, null, $customer->getId(), $context);
        } else {
            $vaultedShopperId = $vaultedShopper->getVaultedShopperId();
            foreach ($this->getCards($customer, $salesChannelId, $context) as $existingCard) {
                $knownCardKeys[$existingCard->getCardKey()] = true;
            }

            $response = $this->blueSnapClient->addCardToVaultedShopper(
                $vaultedShopperId,
                $pfToken,
                $customer->getFirstName(),
                $customer->getLastName(),
                $salesChannelId
            );

            if (\is_array($response) && isset($response['error'])) {
                throw new SavedCardException($this->logger, $this->stringifyError($response['message']), (int) ($response['code'] ?: 400));
            }
        }

        $this->invalidateCache($vaultedShopperId);

        $addedCard = null;
        $cards = $this->getCards($customer, $salesChannelId, $context);
        foreach ($cards as $card) {
            if (!isset($knownCardKeys[$card->getCardKey()])) {
                $addedCard = $card;
                break;
            }
        }

        if ($addedCard === null) {
            throw new SavedCardException($this->logger, 'This card is already saved', 409);
        }

        return $addedCard;
    }

    public function removeCard(?CustomerEntity $customer, string $cardKey, string $salesChannelId, Context $context): bool
    {
        $this->assertEligible($customer, $salesChannelId);

        /** @var CustomerEntity $customer */
        $vaultedShopper = $this->vaultedShopperService->getVaultedShopper($context, $customer->getId());
        if ($vaultedShopper === null) {
            return false;
        }

        $card = $this->findCardByKey($customer, $cardKey, $salesChannelId, $context);
        if ($card === null) {
            return false;
        }

        $response = $this->blueSnapClient->deleteCardFromVaultedShopper(
            $vaultedShopper->getVaultedShopperId(),
            $card->getCardType(),
            $card->getLastFourDigits(),
            $salesChannelId
        );

        if (\is_array($response) && isset($response['error'])) {
            throw new SavedCardException($this->logger, $this->stringifyError($response['message']), (int) ($response['code'] ?: 400));
        }

        $this->invalidateCache($vaultedShopper->getVaultedShopperId());

        if ($card->isPreferred()) {
            $this->vaultedShopperService->setPreferredCard($context, $customer->getId(), null, null);
            $this->getCards($customer, $salesChannelId, $context);
        }

        return true;
    }

    private function createVaultedShopper(CustomerEntity $customer, string $pfToken, string $salesChannelId): string
    {
        $body = [
            'firstName' => $customer->getFirstName(),
            'lastName' => $customer->getLastName(),
            'email' => $customer->getEmail(),
            'paymentSources' => [
                'creditCardInfo' => [
                    ['pfToken' => $pfToken],
                ],
            ],
        ];

        $billingAddress = $customer->getActiveBillingAddress() ?? $customer->getDefaultBillingAddress();
        if ($billingAddress !== null) {
            $country = $billingAddress->getCountry()?->getIso();
            $zip = $billingAddress->getZipcode();
            $city = $billingAddress->getCity();

            if ($country !== null && $country !== '') {
                $body['country'] = strtolower($country);
            }

            if ($zip !== null && $zip !== '') {
                $body['zip'] = $zip;
            }

            if ($city !== '') {
                $body['city'] = $city;
            }
        }

        $response = $this->blueSnapClient->createVaultedShopper($body, $salesChannelId);

        if (\is_array($response) && isset($response['error'])) {
            throw new SavedCardException($this->logger, $this->stringifyError($response['message']), (int) ($response['code'] ?: 400));
        }

        $decoded = json_decode((string) $response, true);
        $vaultedShopperId = $decoded['vaultedShopperId'] ?? null;

        if ($vaultedShopperId === null) {
            throw new SavedCardException($this->logger, 'BlueSnap did not return a vaulted shopper id', 502);
        }

        return (string) $vaultedShopperId;
    }

    /**
     * @param SavedCardStruct[] $cards
     */
    private function applyPreference(
        array $cards,
        CustomerEntity $customer,
        ?string $preferredCardType,
        ?string $preferredLastFour,
        Context $context
    ): void {
        $preferredKey = null;
        if ($preferredCardType !== null && $preferredCardType !== '' && $preferredLastFour !== null && $preferredLastFour !== '') {
            $preferredKey = SavedCardStruct::createCardKey($preferredCardType, $preferredLastFour);
        }

        $matched = null;
        if ($preferredKey !== null) {
            foreach ($cards as $card) {
                if (hash_equals($card->getCardKey(), $preferredKey)) {
                    $matched = $card;
                    break;
                }
            }
        }

        if ($matched === null) {
            $matched = $cards[0];
            $this->vaultedShopperService->setPreferredCard(
                $context,
                $customer->getId(),
                $matched->getCardType(),
                $matched->getLastFourDigits()
            );
        }

        $matched->setPreferred(true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRawCards(string $vaultedShopperId, string $salesChannelId): array
    {
        if (isset($this->cardCache[$vaultedShopperId])) {
            return $this->cardCache[$vaultedShopperId];
        }

        $response = $this->blueSnapClient->getVaultedShopper($vaultedShopperId, $salesChannelId);

        if (\is_array($response) && isset($response['error'])) {
            $this->logger->error('BlueSnap vaulted shopper lookup failed', ['response' => $response]);

            return $this->cardCache[$vaultedShopperId] = [];
        }

        $decoded = json_decode((string) $response, true);
        $rawCards = $decoded['paymentSources']['creditCardInfo'] ?? [];

        return $this->cardCache[$vaultedShopperId] = \is_array($rawCards) ? array_values(array_filter($rawCards, 'is_array')) : [];
    }

    private function invalidateCache(string $vaultedShopperId): void
    {
        unset($this->cardCache[$vaultedShopperId]);
    }

    private function assertEligible(?CustomerEntity $customer, string $salesChannelId): void
    {
        if (!$this->isEnabled($salesChannelId)) {
            throw new SavedCardException($this->logger, 'Saved cards are disabled for this sales channel', 403);
        }

        if (!$this->isEligible($customer)) {
            throw new SavedCardException($this->logger, 'Saved cards are available to registered customers only', 403);
        }
    }

    private function stringifyError(mixed $message): string
    {
        if (\is_string($message)) {
            return $message;
        }

        return json_encode($message) ?: 'BlueSnap request failed';
    }
}
