<?php

declare(strict_types=1);

namespace BlueSnap\Core\Checkout\Cart;

use Symfony\Component\HttpFoundation\RequestStack;

class BlueSnapSurchargeContext
{
    private const SESSION_KEY = 'bluesnap_pf_token';
    private const SESSION_KEY_CARD_TYPE = 'bluesnap_surcharge_card_type';
    private const SESSION_KEY_SURCHARGE_DATA = 'bluesnap_surcharge_data';
    private const SESSION_KEY_VAULTED_SHOPPER_ID = 'bluesnap_vaulted_shopper_id';

    public function __construct(
        private readonly RequestStack $requestStack
    ) {
    }

    public function setSurchargeData(array $data): void
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return;
        }
        $request->getSession()->set(self::SESSION_KEY_SURCHARGE_DATA, $data);
    }

    public function getSurchargeData(): ?array
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return null;
        }
        $data = $request->getSession()->get(self::SESSION_KEY_SURCHARGE_DATA);
        return is_array($data) ? $data : null;
    }

    public function clearSurchargeData(): void
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return;
        }
        $request->getSession()->remove(self::SESSION_KEY_SURCHARGE_DATA);
    }

    public function setPfToken(string $pfToken): void
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return;
        }

        $request->getSession()->set(self::SESSION_KEY, $pfToken);
    }

    public function getPfToken(): ?string
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return null;
        }

        $token = $request->getSession()->get(self::SESSION_KEY);
        return is_string($token) && $token !== '' ? $token : null;
    }

    public function clearPfToken(): void
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return;
        }

        $session = $request->getSession();
        $session->remove(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY_CARD_TYPE);
        $session->remove(self::SESSION_KEY_SURCHARGE_DATA);
    }

    public function setCardType(string $cardType): void
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return;
        }

        $request->getSession()->set(self::SESSION_KEY_CARD_TYPE, $cardType);
    }

    public function getCardType(): ?string
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return null;
        }

        $cardType = $request->getSession()->get(self::SESSION_KEY_CARD_TYPE);
        return is_string($cardType) && $cardType !== '' ? $cardType : null;
    }

    public function getVaultedCustomerId(): ?string
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return null;
        }

        $id = $request->getSession()->get(self::SESSION_KEY_VAULTED_SHOPPER_ID);
        return is_string($id) && $id !== '' ? $id : null;
    }

    public function setVaultedCustomerId(string $vaultedShopperId): void
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return;
        }

        $request->getSession()->set(self::SESSION_KEY_VAULTED_SHOPPER_ID, $vaultedShopperId);
    }

    public function clearVaultedCustomerId(): void
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return;
        }

        $request->getSession()->remove(self::SESSION_KEY_VAULTED_SHOPPER_ID);
    }
}
