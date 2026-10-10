<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Webhook;

use Ernadoo\Boxtal\Exception\BoxtalException;

/**
 * Verifies and reads a webhook request sent by Boxtal.
 *
 * Boxtal signs the raw JSON body with HMAC SHA-256, using the webhook secret of the subscription, in the
 * `x-bxt-signature` header. The documentation does not say how the digest is encoded: other integrations expect
 * lowercase hex, which is the most likely; uppercase hex, base64 and a "sha256=" prefix are accepted too. Always
 * pass the raw body, as received (not a re-encoded JSON).
 */
final class WebhookParser
{
    public const SIGNATURE_HEADER = 'x-bxt-signature';

    public static function isSignatureValid(string $rawBody, string $signature, #[\SensitiveParameter] string $secret): bool
    {
        $signature = trim($signature);
        if (0 === stripos($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }
        if ('' === $signature || '' === $secret) {
            return false;
        }
        $digest = hash_hmac('sha256', $rawBody, $secret, true);

        return hash_equals(bin2hex($digest), strtolower($signature)) || hash_equals(base64_encode($digest), $signature);
    }

    /**
     * @throws BoxtalException When the signature is invalid or the body is not a webhook event
     */
    public static function parse(string $rawBody, string $signature, #[\SensitiveParameter] string $secret): WebhookEvent
    {
        if (!self::isSignatureValid($rawBody, $signature, $secret)) {
            throw new BoxtalException('Invalid Boxtal webhook signature.');
        }
        try {
            $data = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BoxtalException('Invalid Boxtal webhook body: '.$e->getMessage(), 0, $e);
        }
        if (!\is_array($data) || !isset($data['shippingOrderId'])) {
            throw new BoxtalException('Invalid Boxtal webhook body: no shippingOrderId.');
        }

        return WebhookEvent::fromArray($data);
    }
}
