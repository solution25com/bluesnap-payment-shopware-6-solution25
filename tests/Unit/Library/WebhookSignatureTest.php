<?php declare(strict_types=1);

namespace BlueSnap\Tests\Unit\Library;

use BlueSnap\Library\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'w66vk4cnr';

    /** Captured from a real BlueSnap sandbox IPN, so the formula stays pinned to the provider. */
    private const TIMESTAMP = '2026-09-21 13:03:50.558';

    private const BODY = 'transactionType=AUTH_ONLY_SINGLE_CHARGE&referenceNumber=1092751014';

    public function testASignatureFromTheDocumentedFormulaMatches(): void
    {
        $signature = hash_hmac('sha256', self::TIMESTAMP . self::BODY, self::SECRET);

        static::assertTrue(WebhookSignature::matches($signature, self::TIMESTAMP, self::BODY, self::SECRET));
    }

    public function testUppercaseSignaturesAreAccepted(): void
    {
        $signature = strtoupper(WebhookSignature::expected(self::TIMESTAMP, self::BODY, self::SECRET));

        static::assertTrue(WebhookSignature::matches($signature, self::TIMESTAMP, self::BODY, self::SECRET));
    }

    public function testATamperedBodyIsRejected(): void
    {
        $signature = WebhookSignature::expected(self::TIMESTAMP, self::BODY, self::SECRET);

        static::assertFalse(
            WebhookSignature::matches($signature, self::TIMESTAMP, self::BODY . '&invoiceChargeAmount=1.00', self::SECRET)
        );
    }

    public function testADifferentTimestampIsRejected(): void
    {
        $signature = WebhookSignature::expected(self::TIMESTAMP, self::BODY, self::SECRET);

        static::assertFalse(
            WebhookSignature::matches($signature, '2026-09-21 13:03:51.558', self::BODY, self::SECRET)
        );
    }

    public function testADifferentSecretIsRejected(): void
    {
        $signature = WebhookSignature::expected(self::TIMESTAMP, self::BODY, self::SECRET);

        static::assertFalse(WebhookSignature::matches($signature, self::TIMESTAMP, self::BODY, 'other-key'));
    }

    public function testAnEmptySignatureOrSecretIsRejected(): void
    {
        $signature = WebhookSignature::expected(self::TIMESTAMP, self::BODY, self::SECRET);

        static::assertFalse(WebhookSignature::matches('', self::TIMESTAMP, self::BODY, self::SECRET));
        static::assertFalse(WebhookSignature::matches($signature, self::TIMESTAMP, self::BODY, ''));
    }

    public function testAFreshTimestampIsAccepted(): void
    {
        $now = (new \DateTimeImmutable('2026-09-21 13:03:50', new \DateTimeZone('UTC')))->getTimestamp();

        static::assertTrue(WebhookSignature::isFresh(self::TIMESTAMP, $now + 60));
    }

    public function testAStaleTimestampIsRejected(): void
    {
        $now = (new \DateTimeImmutable('2026-09-21 13:03:50', new \DateTimeZone('UTC')))->getTimestamp();

        static::assertFalse(WebhookSignature::isFresh(self::TIMESTAMP, $now + WebhookSignature::TOLERANCE_SECONDS + 1));
        static::assertFalse(WebhookSignature::isFresh(self::TIMESTAMP, $now - WebhookSignature::TOLERANCE_SECONDS - 1));
    }

    public function testATimestampWithoutMillisecondsIsStillRead(): void
    {
        $now = (new \DateTimeImmutable('2026-09-21 13:03:50', new \DateTimeZone('UTC')))->getTimestamp();

        static::assertTrue(WebhookSignature::isFresh('2026-09-21 13:03:50', $now));
    }

    public function testAnUnparsableTimestampIsRejected(): void
    {
        static::assertFalse(WebhookSignature::isFresh('not-a-timestamp'));
    }
}
