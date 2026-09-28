<?php declare(strict_types=1);

namespace BlueSnap\Tests\Unit\Library;

use BlueSnap\Library\CardHolderInfo;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\System\Country\CountryEntity;

class CardHolderInfoTest extends TestCase
{
    public function testTheFullAddressIsSentSoAvsCanRun(): void
    {
        $info = CardHolderInfo::build($this->address(), 'Emi', 'Morse', 'emi@example.com');

        static::assertSame([
            'firstName' => 'Emi',
            'lastName' => 'Morse',
            'email' => 'emi@example.com',
            'address' => '742 Evergreen Terrace',
            'city' => 'Boston',
            'zip' => '02201',
            'state' => 'MA',
            'country' => 'us',
        ], $info);
    }

    /** Shopware stores states as US-MA, BlueSnap expects MA. */
    public function testTheStateCodeIsReducedToTheProviderFormat(): void
    {
        $info = CardHolderInfo::build($this->address('US-CA'), 'Emi', 'Morse', null);

        static::assertSame('CA', $info['state']);
    }

    public function testAStateWithoutAPrefixIsKept(): void
    {
        $info = CardHolderInfo::build($this->address('MA'), 'Emi', 'Morse', null);

        static::assertSame('MA', $info['state']);
    }

    public function testEmptyFieldsAreLeftOutRatherThanSentBlank(): void
    {
        $address = new OrderAddressEntity();
        $address->setStreet('  ');
        $address->setCity('Boston');

        $info = CardHolderInfo::build($address, 'Emi', 'Morse', '');

        static::assertArrayNotHasKey('address', $info);
        static::assertArrayNotHasKey('email', $info);
        static::assertArrayNotHasKey('state', $info);
        static::assertSame('Boston', $info['city']);
    }

    public function testWithoutAnAddressOnlyTheNameIsSent(): void
    {
        $info = CardHolderInfo::build(null, 'Emi', 'Morse', 'emi@example.com');

        static::assertSame(['firstName' => 'Emi', 'lastName' => 'Morse', 'email' => 'emi@example.com'], $info);
    }

    private function address(string $stateCode = 'US-MA'): OrderAddressEntity
    {
        $country = new CountryEntity();
        $country->setIso('US');

        $state = new CountryStateEntity();
        $state->setShortCode($stateCode);

        $address = new OrderAddressEntity();
        $address->setStreet('742 Evergreen Terrace');
        $address->setCity('Boston');
        $address->setZipcode('02201');
        $address->setCountry($country);
        $address->setCountryState($state);

        return $address;
    }
}
