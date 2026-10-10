<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Tracking;

/**
 * Tracking of one package: current status, carrier tracking number and link, history (most recent first).
 * `final` is true once nothing will change any more (delivered, returned…): stop polling then.
 */
final readonly class PackageTracking
{
    /**
     * @param list<TrackingEvent> $history Most recent first
     */
    public function __construct(
        public ?TrackingStatus $status,
        public string $message,
        public bool $final,
        public ?\DateTimeImmutable $at,
        public string $trackingNumber,
        public string $trackingUrl,
        public array $history = [],
        public ?string $packageId = null,
        public ?string $packageExternalId = null,
    ) {
    }

    public function isDelivered(): bool
    {
        return TrackingStatus::Delivered === $this->status;
    }

    /** The parcel was dropped off (some status after "announced") */
    public function isHandedOver(): bool
    {
        if (null !== $this->status && $this->status->isHandedOver()) {
            return true;
        }
        foreach ($this->history as $event) {
            if (null !== $event->status && $event->status->isHandedOver()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $history = [];
        foreach ((array) ($data['history'] ?? []) as $event) {
            if (\is_array($event)) {
                $history[] = TrackingEvent::fromArray($event);
            }
        }

        return new self(
            status: TrackingStatus::tryFrom((string) ($data['status'] ?? '')),
            message: (string) ($data['message'] ?? ''),
            final: (bool) ($data['isFinal'] ?? false),
            at: self::dateTime($data['trackingDateTime'] ?? null),
            trackingNumber: (string) ($data['trackingNumber'] ?? ''),
            trackingUrl: (string) ($data['packageTrackingUrl'] ?? ''),
            history: $history,
            packageId: isset($data['packageId']) ? (string) $data['packageId'] : null,
            packageExternalId: isset($data['packageExternalId']) ? (string) $data['packageExternalId'] : null,
        );
    }

    /**
     * "2026-10-10 12:30:03" (Boxtal gives no time zone: French time).
     *
     * @internal
     */
    public static function dateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('Europe/Paris'))
            ?: (\DateTimeImmutable::createFromFormat(\DATE_ATOM, $value) ?: null);
    }
}
