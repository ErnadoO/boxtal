<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Webhook;

/**
 * A webhook subscription. The secret you gave when subscribing signs the requests sent to `callbackUrl` (header
 * x-bxt-signature); Boxtal never returns it (`webhookSecret` is null in its responses).
 */
final readonly class Subscription
{
    public function __construct(
        public string $id,
        public ?EventType $eventType,
        public string $callbackUrl,
        public string $status,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            eventType: EventType::tryFrom((string) ($data['eventType'] ?? '')),
            callbackUrl: (string) ($data['callbackUrl'] ?? ''),
            status: (string) ($data['status'] ?? ''),
        );
    }
}
