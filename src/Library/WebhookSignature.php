<?php

declare(strict_types=1);

namespace BlueSnap\Library;

/**
 * BlueSnap signs an IPN with HMAC-SHA256 over the Bls-Ipn-Timestamp value directly followed by the
 * raw body, keyed with the Security Header value from the merchant portal.
 */
class WebhookSignature
{
    public const TOLERANCE_SECONDS = 900;

    public static function expected(string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . $rawBody, $secret);
    }

    public static function matches(string $signature, string $timestamp, string $rawBody, string $secret): bool
    {
        if ($signature === '' || $secret === '') {
            return false;
        }

        return hash_equals(self::expected($timestamp, $rawBody, $secret), strtolower($signature));
    }

    public static function isFresh(string $timestamp, ?int $now = null): bool
    {
        $sent = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $timestamp, new \DateTimeZone('UTC'));
        if ($sent === false) {
            $sent = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', explode('.', $timestamp)[0], new \DateTimeZone('UTC'));
        }

        if ($sent === false) {
            return false;
        }

        return abs(($now ?? time()) - $sent->getTimestamp()) <= self::TOLERANCE_SECONDS;
    }
}
