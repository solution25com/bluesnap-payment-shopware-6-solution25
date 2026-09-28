<?php

declare(strict_types=1);

namespace BlueSnap\Library;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;

class CardHolderInfo
{
    /**
     * @return array<string, string>
     */
    public static function build(
        CustomerAddressEntity|OrderAddressEntity|null $address,
        string $firstName,
        string $lastName,
        ?string $email
    ): array {
        $info = [
            'firstName' => $firstName,
            'lastName' => $lastName,
        ];

        if ($email !== null && $email !== '') {
            $info['email'] = $email;
        }

        if ($address === null) {
            return $info;
        }

        $country = $address->getCountry()?->getIso();
        $state = $address->getCountryState()?->getShortCode();

        $fields = [
            'address' => $address->getStreet(),
            'address2' => $address->getAdditionalAddressLine1(),
            'city' => $address->getCity(),
            'zip' => $address->getZipCode(),
            'state' => self::normalizeState($state),
            'country' => $country !== null ? strtolower($country) : null,
        ];

        foreach ($fields as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $info[$key] = trim($value);
            }
        }

        return $info;
    }

    private static function normalizeState(?string $shortCode): ?string
    {
        if ($shortCode === null || $shortCode === '') {
            return null;
        }

        $parts = explode('-', $shortCode);

        return end($parts) ?: null;
    }
}
