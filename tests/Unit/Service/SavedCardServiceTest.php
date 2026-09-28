<?php declare(strict_types=1);

namespace BlueSnap\Tests\Unit\Service;

use BlueSnap\Core\Content\VaultedShopper\SavedCardStruct;
use BlueSnap\Core\Content\VaultedShopper\VaultedShopperEntity;
use BlueSnap\Exceptions\SavedCardException;
use BlueSnap\Service\BlueSnapApiClient;
use BlueSnap\Service\BlueSnapConfig;
use BlueSnap\Service\SavedCardService;
use BlueSnap\Service\VaultedShopperService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;

class SavedCardServiceTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'sales-channel-1';

    private BlueSnapApiClient&MockObject $client;
    private VaultedShopperService&MockObject $vaultedShopperService;
    private BlueSnapConfig&MockObject $config;
    private Context $context;
    private CustomerEntity $customer;
    private VaultedShopperEntity $vaultedShopper;

    protected function setUp(): void
    {
        $this->client = $this->createMock(BlueSnapApiClient::class);
        $this->vaultedShopperService = $this->createMock(VaultedShopperService::class);
        $this->config = $this->createMock(BlueSnapConfig::class);
        $this->context = Context::createDefaultContext();

        $this->customer = new CustomerEntity();
        $this->customer->setId('customer-1');
        $this->customer->setGuest(false);
        $this->customer->setFirstName('John');
        $this->customer->setLastName('Doe');

        $this->vaultedShopper = new VaultedShopperEntity();
        $this->vaultedShopper->setId('row-1');
        $this->vaultedShopper->setVaultedShopperId('19585102');

        $this->config->method('getConfig')->willReturnCallback(
            static fn (string $name) => $name === 'vaultedShopper'
        );
    }

    private function service(): SavedCardService
    {
        return new SavedCardService($this->client, $this->vaultedShopperService, $this->config, new NullLogger());
    }

    private function shopperPayload(): string
    {
        return (string) json_encode(['paymentSources' => ['creditCardInfo' => [
            [
                'billingContactInfo' => ['firstName' => 'John', 'lastName' => 'Doe'],
                'creditCard' => ['cardType' => 'VISA', 'cardLastFourDigits' => '1111', 'expirationMonth' => '02', 'expirationYear' => '2030'],
            ],
            ['creditCard' => ['cardType' => 'MASTERCARD', 'cardLastFourDigits' => '0002', 'expirationMonth' => '11', 'expirationYear' => '2029']],
            ['creditCard' => ['cardType' => 'AMEX']],
            ['nonsense' => true],
        ]]]);
    }

    private function expectShopperLookup(): void
    {
        $this->vaultedShopperService->method('getVaultedShopper')->willReturn($this->vaultedShopper);
    }

    private function expectNoShopper(): void
    {
        $this->vaultedShopperService->method('getVaultedShopper')->willReturn(null);
    }

    public function testAllCardsAreReadNotOnlyTheFirst(): void
    {
        $this->expectShopperLookup();
        $this->client->method('getVaultedShopper')->willReturn($this->shopperPayload());

        $cards = $this->service()->getCards($this->customer, self::SALES_CHANNEL_ID, $this->context);

        static::assertCount(2, $cards);
        static::assertSame(['VISA', 'MASTERCARD'], array_map(static fn ($c) => $c->getCardType(), $cards));
    }

    public function testTheCardListIsFetchedOncePerRequest(): void
    {
        $this->expectShopperLookup();
        $this->client->expects(static::once())->method('getVaultedShopper')->willReturn($this->shopperPayload());

        $service = $this->service();
        $service->getCards($this->customer, self::SALES_CHANNEL_ID, $this->context);
        $service->getCards($this->customer, self::SALES_CHANNEL_ID, $this->context);
        $service->getPreferredCard($this->customer, self::SALES_CHANNEL_ID, $this->context);
    }

    public function testFirstCardBecomesPreferredWhenNothingIsStored(): void
    {
        $this->expectShopperLookup();
        $this->client->method('getVaultedShopper')->willReturn($this->shopperPayload());
        $this->vaultedShopperService->expects(static::once())
            ->method('setPreferredCard')
            ->with(static::anything(), 'customer-1', 'VISA', '1111');

        static::assertSame('VISA', $this->service()->getPreferredCard($this->customer, self::SALES_CHANNEL_ID, $this->context)?->getCardType());
    }

    public function testStoredPreferenceIsHonouredWithoutRewriting(): void
    {
        $this->vaultedShopper->setPreferredCardType('MASTERCARD');
        $this->vaultedShopper->setPreferredCardLastFour('0002');
        $this->expectShopperLookup();
        $this->client->method('getVaultedShopper')->willReturn($this->shopperPayload());
        $this->vaultedShopperService->expects(static::never())->method('setPreferredCard');

        static::assertSame('MASTERCARD', $this->service()->getPreferredCard($this->customer, self::SALES_CHANNEL_ID, $this->context)?->getCardType());
    }

    public function testPreferenceForACardRemovedInBlueSnapHealsItself(): void
    {
        $this->vaultedShopper->setPreferredCardType('DISCOVER');
        $this->vaultedShopper->setPreferredCardLastFour('9999');
        $this->expectShopperLookup();
        $this->client->method('getVaultedShopper')->willReturn($this->shopperPayload());
        $this->vaultedShopperService->expects(static::once())
            ->method('setPreferredCard')
            ->with(static::anything(), 'customer-1', 'VISA', '1111');

        static::assertSame('VISA', $this->service()->getPreferredCard($this->customer, self::SALES_CHANNEL_ID, $this->context)?->getCardType());
    }

    public function testCardKeyResolvesToTheBlueSnapTransactionSelector(): void
    {
        $this->expectShopperLookup();
        $this->client->method('getVaultedShopper')->willReturn($this->shopperPayload());

        $selector = $this->service()->getCardSelector(
            $this->customer,
            SavedCardStruct::createCardKey('MASTERCARD', '0002'),
            self::SALES_CHANNEL_ID,
            $this->context
        );

        static::assertSame(['cardType' => 'MASTERCARD', 'cardLastFourDigits' => '0002'], $selector);
    }

    public function testACardKeyThatIsNotTheCustomersOwnIsRejected(): void
    {
        $this->expectShopperLookup();
        $this->client->method('getVaultedShopper')->willReturn($this->shopperPayload());

        static::assertNull($this->service()->getCardSelector(
            $this->customer,
            SavedCardStruct::createCardKey('DISCOVER', '4242'),
            self::SALES_CHANNEL_ID,
            $this->context
        ));
        static::assertFalse($this->service()->setPreferredCard(
            $this->customer,
            str_repeat('a', 32),
            self::SALES_CHANNEL_ID,
            $this->context
        ));
    }

    public function testGuestsHaveNoSavedCards(): void
    {
        $guest = new CustomerEntity();
        $guest->setId('guest-1');
        $guest->setGuest(true);

        static::assertSame([], $this->service()->getCards($guest, self::SALES_CHANNEL_ID, $this->context));
        static::assertSame([], $this->service()->getCards(null, self::SALES_CHANNEL_ID, $this->context));
    }

    public function testNoCardsWhenTheFeatureIsDisabled(): void
    {
        $config = $this->createMock(BlueSnapConfig::class);
        $config->method('getConfig')->willReturn(false);
        $service = new SavedCardService($this->client, $this->vaultedShopperService, $config, new NullLogger());

        static::assertSame([], $service->getCards($this->customer, self::SALES_CHANNEL_ID, $this->context));
        static::assertFalse($service->isEnabled(self::SALES_CHANNEL_ID));
    }

    public function testNoCardsWhenTheCustomerHasNoVaultedShopper(): void
    {
        $this->expectNoShopper();
        $this->client->expects(static::never())->method('getVaultedShopper');

        static::assertSame([], $this->service()->getCards($this->customer, self::SALES_CHANNEL_ID, $this->context));
    }

    public function testAFailingBlueSnapLookupYieldsAnEmptyListRatherThanAnError(): void
    {
        $this->expectShopperLookup();
        $this->client->method('getVaultedShopper')->willReturn(['error' => true, 'code' => 500, 'message' => 'boom']);

        static::assertSame([], $this->service()->getCards($this->customer, self::SALES_CHANNEL_ID, $this->context));
    }

    public function testRemovingAnUnknownCardReportsFailure(): void
    {
        $this->expectShopperLookup();
        $this->client->method('getVaultedShopper')->willReturn($this->shopperPayload());
        $this->client->expects(static::never())->method('deleteCardFromVaultedShopper');

        static::assertFalse($this->service()->removeCard($this->customer, str_repeat('b', 32), self::SALES_CHANNEL_ID, $this->context));
    }

    public function testRemovingThePreferredCardClearsThePreference(): void
    {
        $this->expectShopperLookup();
        $this->client->method('getVaultedShopper')->willReturn($this->shopperPayload());
        $this->client->expects(static::once())
            ->method('deleteCardFromVaultedShopper')
            ->with('19585102', 'VISA', '1111', self::SALES_CHANNEL_ID)
            ->willReturn('{}');

        $clearing = 0;
        $this->vaultedShopperService->method('setPreferredCard')
            ->willReturnCallback(static function ($ctx, $customerId, $type, $lastFour) use (&$clearing): void {
                if ($type === null && $lastFour === null) {
                    ++$clearing;
                }
            });

        $removed = $this->service()->removeCard(
            $this->customer,
            SavedCardStruct::createCardKey('VISA', '1111'),
            self::SALES_CHANNEL_ID,
            $this->context
        );

        static::assertTrue($removed);
        static::assertSame(1, $clearing);
    }

    public function testRemovalPropagatesABlueSnapError(): void
    {
        $this->expectShopperLookup();
        $this->client->method('getVaultedShopper')->willReturn($this->shopperPayload());
        $this->client->method('deleteCardFromVaultedShopper')->willReturn(['error' => true, 'code' => 400, 'message' => 'nope']);

        $this->expectException(SavedCardException::class);

        $this->service()->removeCard(
            $this->customer,
            SavedCardStruct::createCardKey('VISA', '1111'),
            self::SALES_CHANNEL_ID,
            $this->context
        );
    }

    public function testAddingACardThatIsAlreadySavedIsReportedAsAConflict(): void
    {
        $this->expectShopperLookup();
        $this->client->method('getVaultedShopper')->willReturn($this->shopperPayload());
        $this->client->method('addCardToVaultedShopper')->willReturn('{}');

        $this->expectException(SavedCardException::class);
        $this->expectExceptionCode(409);

        $this->service()->addCard($this->customer, 'pf-token', self::SALES_CHANNEL_ID, $this->context);
    }

    public function testAddingACardReturnsTheNewCard(): void
    {
        $this->expectShopperLookup();

        $before = $this->shopperPayload();
        $after = (string) json_encode(['paymentSources' => ['creditCardInfo' => [
            ['creditCard' => ['cardType' => 'VISA', 'cardLastFourDigits' => '1111', 'expirationMonth' => '02', 'expirationYear' => '2030']],
            ['creditCard' => ['cardType' => 'MASTERCARD', 'cardLastFourDigits' => '0002', 'expirationMonth' => '11', 'expirationYear' => '2029']],
            ['creditCard' => ['cardType' => 'DISCOVER', 'cardLastFourDigits' => '4242', 'expirationMonth' => '06', 'expirationYear' => '2031']],
        ]]]);

        $this->client->method('getVaultedShopper')->willReturnOnConsecutiveCalls($before, $after);
        $this->client->expects(static::once())
            ->method('addCardToVaultedShopper')
            ->with('19585102', 'pf-token', 'John', 'Doe', self::SALES_CHANNEL_ID)
            ->willReturn('{}');

        $card = $this->service()->addCard($this->customer, 'pf-token', self::SALES_CHANNEL_ID, $this->context);

        static::assertSame('DISCOVER', $card->getCardType());
        static::assertSame('4242', $card->getLastFourDigits());
    }

    public function testGuestsCannotAddCards(): void
    {
        $guest = new CustomerEntity();
        $guest->setId('guest-1');
        $guest->setGuest(true);

        $this->expectException(SavedCardException::class);
        $this->expectExceptionCode(403);

        $this->service()->addCard($guest, 'pf-token', self::SALES_CHANNEL_ID, $this->context);
    }

    public function testTheAddCardTokenIsNotBoundToTheVaultedShopper(): void
    {
        $this->client->expects(static::once())
            ->method('makeTokenRequest')
            ->with([], self::SALES_CHANNEL_ID)
            ->willReturn('token-123');

        static::assertSame('token-123', $this->service()->createAddCardToken($this->customer, self::SALES_CHANNEL_ID, $this->context));
    }

    public function testTokenCreationFailureIsReported(): void
    {
        $this->client->method('makeTokenRequest')->willReturn(['error' => true, 'code' => 500, 'message' => 'boom']);

        $this->expectException(SavedCardException::class);

        $this->service()->createAddCardToken($this->customer, self::SALES_CHANNEL_ID, $this->context);
    }
}
