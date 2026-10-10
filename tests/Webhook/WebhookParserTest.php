<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Tests\Webhook;

use Ernadoo\Boxtal\Exception\BoxtalException;
use Ernadoo\Boxtal\Tracking\TrackingStatus;
use Ernadoo\Boxtal\Webhook\EventType;
use Ernadoo\Boxtal\Webhook\WebhookParser;
use PHPUnit\Framework\TestCase;

final class WebhookParserTest extends TestCase
{
    private const SECRET = 'webhook-secret';

    public function testATrackingEventSignedInHexIsRead(): void
    {
        $body = $this->trackingBody();

        $event = WebhookParser::parse($body, hash_hmac('sha256', $body, self::SECRET), self::SECRET);

        $this->assertSame(EventType::TrackingChanged, $event->type);
        $this->assertSame('2610101141POFR51LIFR', $event->shippingOrderId);
        $this->assertSame('troc-42', $event->shipmentExternalId);
        $this->assertSame(TrackingStatus::Delivered, $event->trackings[0]->status);
    }

    public function testABase64SignatureIsAcceptedToo(): void
    {
        $body = $this->trackingBody();

        $this->assertTrue(WebhookParser::isSignatureValid($body, base64_encode(hash_hmac('sha256', $body, self::SECRET, true)), self::SECRET));
    }

    public function testASignaturePrefixedWithTheAlgorithmIsAccepted(): void
    {
        $body = '{"id":"evt-1","type":"TRACKING_CHANGED","shippingOrderId":"ORDER-1","payload":{"trackings":[]}}';

        $this->assertTrue(WebhookParser::isSignatureValid($body, 'sha256='.hash_hmac('sha256', $body, 'secret'), 'secret'));
        $this->assertTrue(WebhookParser::isSignatureValid($body, strtoupper(hash_hmac('sha256', $body, 'secret')), 'secret'));
        $this->assertFalse(WebhookParser::isSignatureValid($body, 'sha256=', 'secret'));
    }

    public function testADocumentEventGivesTheLabel(): void
    {
        $body = json_encode(['id' => 'evt-1', 'type' => 'DOCUMENT_CREATED', 'shippingOrderId' => '2610101341MONR51M2FR', 'shipmentExternalId' => 'troc-42',
            'payload' => ['documents' => [['type' => 'LABEL', 'format' => 'PDF_A4', 'url' => 'https://document.boxtal.build/label.pdf']]]], \JSON_THROW_ON_ERROR);

        $event = WebhookParser::parse($body, hash_hmac('sha256', $body, self::SECRET), self::SECRET);

        $this->assertSame(EventType::DocumentCreated, $event->type);
        $this->assertTrue($event->documents[0]->isLabel());
    }

    public function testAWrongSignatureIsRefused(): void
    {
        $body = $this->trackingBody();

        $this->assertFalse(WebhookParser::isSignatureValid($body, hash_hmac('sha256', $body, 'other'), self::SECRET));
        $this->assertFalse(WebhookParser::isSignatureValid($body, '', self::SECRET));
        $this->expectException(BoxtalException::class);
        WebhookParser::parse($body.' ', hash_hmac('sha256', $body, self::SECRET), self::SECRET);
    }

    private function trackingBody(): string
    {
        $tracking = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/tracking.json'), true)['content'];

        return json_encode(['id' => 'evt-2', 'type' => 'TRACKING_CHANGED', 'shippingOrderId' => '2610101141POFR51LIFR', 'shipmentExternalId' => 'troc-42',
            'payload' => ['trackings' => $tracking]], \JSON_THROW_ON_ERROR);
    }
}
