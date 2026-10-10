<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Tracking;

final readonly class TrackingEvent
{
    public function __construct(
        public ?TrackingStatus $status,
        public string $message,
        public ?\DateTimeImmutable $at,
        public ?string $location = null,
        public bool $final = false,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: TrackingStatus::tryFrom((string) ($data['status'] ?? '')),
            message: (string) ($data['message'] ?? ''),
            at: PackageTracking::dateTime($data['trackingDateTime'] ?? null),
            location: isset($data['location']) && '' !== $data['location'] ? (string) $data['location'] : null,
            final: (bool) ($data['isFinal'] ?? false),
        );
    }
}
