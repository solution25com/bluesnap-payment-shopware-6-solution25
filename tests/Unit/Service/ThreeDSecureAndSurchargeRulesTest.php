<?php declare(strict_types=1);

namespace BlueSnap\Tests\Unit\Service;

use BlueSnap\Core\Checkout\Cart\BlueSnapSurchargeCartProcessor;
use BlueSnap\Core\Content\BlueSnap\SalesChannel\BlueSnapRoute;
use BlueSnap\Service\OrderSurchargeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;

class ThreeDSecureAndSurchargeRulesTest extends TestCase
{
    /**
     * BlueSnap asks for BYPASSED and UNAVAILABLE to be processed without 3-D Secure; only a
     * reported failure blocks the payment.
     */
    #[DataProvider('threeDSecureResults')]
    public function testOnlyTheDocumentedResultsArePassed(string $authResult, bool $expected): void
    {
        static::assertSame($expected, BlueSnapRoute::isAcceptable3DSecureResult($authResult));
    }

    public static function threeDSecureResults(): array
    {
        return [
            'succeeded' => ['AUTHENTICATION_SUCCEEDED', true],
            'bypassed' => ['AUTHENTICATION_BYPASSED', true],
            'unavailable' => ['AUTHENTICATION_UNAVAILABLE', true],
            'failed' => ['AUTHENTICATION_FAILED', false],
            'incomplete' => ['AUTHENTICATION_INCOMPLETE', false],
            'empty' => ['', false],
            'unknown' => ['SOMETHING_ELSE', false],
        ];
    }

    /** The cart and this service each add the surcharge their own way, and both have to be found. */
    public function testASurchargeFromTheCartIsRecognised(): void
    {
        static::assertTrue(OrderSurchargeService::isSurchargeLineItem(
            BlueSnapSurchargeCartProcessor::SURCHARGE_LINE_ITEM_TYPE,
            BlueSnapSurchargeCartProcessor::SURCHARGE_LINE_ITEM_ID
        ));
    }

    public function testASurchargeAddedToTheOrderIsRecognised(): void
    {
        static::assertTrue(OrderSurchargeService::isSurchargeLineItem(
            LineItem::CUSTOM_LINE_ITEM_TYPE,
            OrderSurchargeService::ORDER_LINE_ITEM_ID
        ));
    }

    public function testAnOrdinaryCustomLineItemIsNotASurcharge(): void
    {
        static::assertFalse(OrderSurchargeService::isSurchargeLineItem(LineItem::CUSTOM_LINE_ITEM_TYPE, 'gift-wrap'));
        static::assertFalse(OrderSurchargeService::isSurchargeLineItem(LineItem::PRODUCT_LINE_ITEM_TYPE, 'some-product'));
        static::assertFalse(OrderSurchargeService::isSurchargeLineItem(null, null));
    }

    /**
     * The order line item must not carry the cart processor's identifier: a recalculation lets the
     * processor try to remove it, and it is not removable.
     */
    public function testTheOrderSurchargeUsesItsOwnIdentifier(): void
    {
        static::assertNotSame(
            BlueSnapSurchargeCartProcessor::SURCHARGE_LINE_ITEM_ID,
            OrderSurchargeService::ORDER_LINE_ITEM_ID
        );
    }
}
