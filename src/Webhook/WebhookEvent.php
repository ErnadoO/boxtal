<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Webhook;

use Ernadoo\Boxtal\Shipping\ShippingDocument;
use Ernadoo\Boxtal\Tracking\PackageTracking;

/**
 * An event received on a webhook: documents of a shipping order (DOCUMENT_CREATED) or its tracking
 * (TRACKING_CHANGED). `shipmentExternalId` is the externalId given when ordering: use it to find your shipment.
 */
final readonly class WebhookEvent
{
    /**
     * @param list<ShippingDocument> $documents Filled for DOCUMENT_CREATED
     * @param list<PackageTracking>  $trackings Filled for TRACKING_CHANGED
     */
    public function __construct(
        public string $id,
        public ?EventType $type,
        public string $shippingOrderId,
        public ?string $shipmentExternalId,
        public array $documents = [],
        public array $trackings = [],
    ) {
    }

    /**
     * @param array<mixed> $data Decoded JSON body of the webhook request
     */
    public static function fromArray(array $data): self
    {
        $payload = (array) ($data['payload'] ?? []);
        $documents = [];
        foreach ((array) ($payload['documents'] ?? []) as $document) {
            if (\is_array($document)) {
                $documents[] = ShippingDocument::fromArray($document);
            }
        }
        $trackings = [];
        foreach ((array) ($payload['trackings'] ?? []) as $tracking) {
            if (\is_array($tracking)) {
                $trackings[] = PackageTracking::fromArray($tracking);
            }
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            type: EventType::tryFrom((string) ($data['type'] ?? '')),
            shippingOrderId: (string) ($data['shippingOrderId'] ?? ''),
            shipmentExternalId: isset($data['shipmentExternalId']) ? (string) $data['shipmentExternalId'] : null,
            documents: $documents,
            trackings: $trackings,
        );
    }
}
