<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Shipping;

/**
 * A shipping order as returned by Boxtal (creation, reading, cancellation).
 * Prices are excluding tax: the quote gives the price including tax.
 */
final readonly class ShippingOrder
{
    public function __construct(
        public string $id,
        public ShippingOrderStatus $status,
        public ?float $priceExclTax,
        public ?float $insurancePriceExclTax,
        public ?\DateTimeImmutable $expectedTakingOverDate,
        public ?\DateTimeImmutable $estimatedDeliveryDate,
        public ?string $shipmentId = null,
    ) {
    }

    public function isCancelled(): bool
    {
        return ShippingOrderStatus::Cancelled === $this->status;
    }

    /**
     * @param array<mixed> $data The "content" of the response
     */
    public static function fromArray(array $data): self
    {
        $date = static fn (mixed $v): ?\DateTimeImmutable => \is_string($v) && '' !== $v ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $v) ?: null) : null;
        $money = static fn (mixed $v): ?float => \is_array($v) && isset($v['value']) ? (float) $v['value'] : null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            status: ShippingOrderStatus::tryFrom((string) ($data['status'] ?? '')) ?? ShippingOrderStatus::Pending,
            priceExclTax: $money($data['deliveryPriceExclTax'] ?? null),
            insurancePriceExclTax: $money($data['insurancePriceExclTax'] ?? null),
            expectedTakingOverDate: $date($data['expectedTakingOverDate'] ?? null),
            estimatedDeliveryDate: $date($data['estimatedDeliveryDate'] ?? null),
            shipmentId: isset($data['shipmentId']) ? (string) $data['shipmentId'] : null,
        );
    }
}
