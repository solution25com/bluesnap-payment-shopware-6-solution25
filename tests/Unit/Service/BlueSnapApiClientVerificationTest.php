<?php declare(strict_types=1);

namespace BlueSnap\Tests\Unit\Service;

use BlueSnap\Service\BlueSnapApiClient;
use PHPUnit\Framework\TestCase;

class BlueSnapApiClientVerificationTest extends TestCase
{
    public function testCodesAreReadFromAProcessedTransaction(): void
    {
        $codes = BlueSnapApiClient::extractVerificationCodes([
            'processingInfo' => [
                'processingStatus' => 'SUCCESS',
                'cvvResponseCode' => 'NM',
                'avsResponseCodeZip' => 'U',
                'avsResponseCodeAddress' => 'M',
                'avsResponseCodeName' => 'N',
            ],
        ]);

        static::assertSame('NM', $codes['cvvResponseCode']);
        static::assertSame('Z:U A:M N:N', $codes['avsResponseCode']);
    }

    public function testAJsonStringIsAccepted(): void
    {
        $codes = BlueSnapApiClient::extractVerificationCodes('{"processingInfo":{"cvvResponseCode":"ND"}}');

        static::assertSame('ND', $codes['cvvResponseCode']);
        static::assertNull($codes['avsResponseCode']);
    }

    /** A vaulted card is charged without a CVV, and no code must be invented for it. */
    public function testAVaultedChargeReportsNoCodes(): void
    {
        $codes = BlueSnapApiClient::extractVerificationCodes([
            'processingInfo' => ['processingStatus' => 'SUCCESS'],
        ]);

        static::assertNull($codes['cvvResponseCode']);
        static::assertNull($codes['avsResponseCode']);
    }

    public function testMalformedInputIsTolerated(): void
    {
        foreach ([null, 'not json', [], ['processingInfo' => 'nonsense']] as $input) {
            $codes = BlueSnapApiClient::extractVerificationCodes($input);

            static::assertNull($codes['cvvResponseCode']);
            static::assertNull($codes['avsResponseCode']);
        }
    }

    public function testEmptyCodesAreNotStored(): void
    {
        $codes = BlueSnapApiClient::extractVerificationCodes([
            'processingInfo' => ['cvvResponseCode' => '', 'avsResponseCodeZip' => ''],
        ]);

        static::assertNull($codes['cvvResponseCode']);
        static::assertNull($codes['avsResponseCode']);
    }
}
