<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Shipping;

/**
 * A shipping order (v3, POST /v3.1/shipping-order).
 *
 * Offers delivered to a pickup point need the recipient's point ($pickupPointCode, the code returned by the parcel
 * point search, e.g. "40246"); Boxtal answers "'shipment.pickupPointCode' missing value" otherwise. The drop-off point
 * is optional: the sender drops the parcel in any point of the network.
 */
final readonly class ShippingOrderRequest
{
    /**
     * @param string        $offerCode Offer code from a quote, e.g. "MONR-CpourToi" (Offer::code())
     * @param list<Package> $packages
     */
    public function __construct(
        public string $offerCode,
        public Address $from,
        public Address $to,
        public array $packages,
        public ?string $pickupPointCode = null,
        public ?string $dropoffPointCode = null,
        public ?string $externalId = null,
        public LabelFormat $labelFormat = LabelFormat::A4,
        public bool $insured = false,
        public ?\DateTimeInterface $expectedTakingOverDate = null,
    ) {
        if ([] === $packages) {
            throw new \InvalidArgumentException('A shipping order needs at least one package.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $shipment = [
            'fromAddress' => $this->from->toArray(),
            'toAddress' => $this->to->toArray(),
            'packages' => array_map(static fn (Package $p): array => $p->toArray(), $this->packages),
        ];
        foreach (['pickupPointCode' => $this->pickupPointCode, 'dropoffPointCode' => $this->dropoffPointCode, 'externalId' => $this->externalId] as $key => $value) {
            if (null !== $value && '' !== $value) {
                $shipment[$key] = $value;
            }
        }
        $order = ['shippingOfferCode' => $this->offerCode, 'labelType' => $this->labelFormat->value, 'insured' => $this->insured, 'shipment' => $shipment];
        if (null !== $this->expectedTakingOverDate) {
            $order['expectedTakingOverDate'] = $this->expectedTakingOverDate->format('Y-m-d');
        }

        return $order;
    }
}
