<?php declare(strict_types=1);

namespace BlueSnap\Tests\Unit\Core\Content\VaultedShopper;

use BlueSnap\Core\Content\VaultedShopper\SavedCardStruct;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SavedCardStructTest extends TestCase
{
    public function testCardKeyIsDeterministicAndCaseInsensitive(): void
    {
        static::assertSame(
            SavedCardStruct::createCardKey('VISA', '1111'),
            SavedCardStruct::createCardKey('visa', '1111')
        );
    }

    public function testCardKeyMatchesTheRouteRequirement(): void
    {
        static::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', SavedCardStruct::createCardKey('VISA', '1111'));
    }

    public function testDifferentCardsProduceDifferentKeys(): void
    {
        static::assertNotSame(
            SavedCardStruct::createCardKey('VISA', '1111'),
            SavedCardStruct::createCardKey('MASTERCARD', '1111')
        );
        static::assertNotSame(
            SavedCardStruct::createCardKey('VISA', '1111'),
            SavedCardStruct::createCardKey('VISA', '2222')
        );
    }

    public function testExpirationMonthIsPadded(): void
    {
        $card = new SavedCardStruct('VISA', '1111', '2', '2030');

        static::assertSame('02', $card->getExpirationMonth());
        static::assertSame('02/2030', $card->getExpirationDate());
    }

    public function testExpirationDateIsEmptyWhenBlueSnapOmitsIt(): void
    {
        $card = new SavedCardStruct('VISA', '1111');

        static::assertSame('', $card->getExpirationDate());
        static::assertFalse($card->isExpired());
    }

    public function testExpiredCardIsDetected(): void
    {
        $past = new SavedCardStruct('VISA', '1111', '01', '2020');
        $future = new SavedCardStruct('VISA', '1111', '12', (string) ((int) date('Y') + 2));

        static::assertTrue($past->isExpired());
        static::assertFalse($future->isExpired());
    }

    public function testCardExpiringThisMonthIsNotExpiredYet(): void
    {
        $card = new SavedCardStruct('VISA', '1111', date('m'), date('Y'));

        static::assertFalse($card->isExpired());
    }

    public function testCardTypeIsNormalisedToUppercase(): void
    {
        static::assertSame('MASTERCARD', (new SavedCardStruct('mastercard', '0002'))->getCardType());
    }

    public function testFromVaultedShopperCardMapsTheApiShape(): void
    {
        $card = SavedCardStruct::fromVaultedShopperCard([
            'billingContactInfo' => ['firstName' => 'John', 'lastName' => 'Doe'],
            'creditCard' => [
                'cardType' => 'VISA',
                'cardLastFourDigits' => '1111',
                'expirationMonth' => '2',
                'expirationYear' => '2030',
            ],
        ]);

        static::assertNotNull($card);
        static::assertSame('VISA', $card->getCardType());
        static::assertSame('1111', $card->getLastFourDigits());
        static::assertSame('02/2030', $card->getExpirationDate());
        static::assertSame('John Doe', $card->getCardHolderName());
        static::assertFalse($card->isPreferred());
    }

    /**
     * @param array<string, mixed> $raw
     */
    #[DataProvider('malformedCardProvider')]
    public function testMalformedEntriesAreSkipped(array $raw): void
    {
        static::assertNull(SavedCardStruct::fromVaultedShopperCard($raw));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function malformedCardProvider(): array
    {
        return [
            'no creditCard key' => [['billingContactInfo' => ['firstName' => 'John']]],
            'creditCard not an array' => [['creditCard' => 'nonsense']],
            'missing card type' => [['creditCard' => ['cardLastFourDigits' => '1111']]],
            'missing last four' => [['creditCard' => ['cardType' => 'VISA']]],
            'empty entry' => [[]],
        ];
    }

    public function testSerialisationCarriesNoSensitiveFields(): void
    {
        $json = json_encode((new SavedCardStruct('VISA', '1111', '02', '2030', 'John', 'Doe', true))->jsonSerialize());

        static::assertIsString($json);
        static::assertDoesNotMatchRegularExpression('/pfToken|cardNumber|securityCode|cvv/i', $json);
        static::assertStringContainsString('"lastFourDigits":"1111"', $json);
    }
}
