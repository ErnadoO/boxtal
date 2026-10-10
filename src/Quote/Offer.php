<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Quote;

/**
 * One transport offer of a quote, e.g. Chronopost Shop2Shop at 3.75 € excl. tax, dropped off in a relay point on
 * 2026-10-10 and delivered to a pickup point on 2026-10-13.
 */
final readonly class Offer
{
    /** Drop-off in a carrier relay point */
    public const COLLECTION_DROPOFF_POINT = 'DROPOFF_POINT';
    /** Drop-off at the post office */
    public const COLLECTION_POST_OFFICE = 'POST_OFFICE';
    /** Collected at the sender's address */
    public const COLLECTION_HOME = 'HOME';
    public const DELIVERY_PICKUP_POINT = 'PICKUP_POINT';
    public const DELIVERY_HOME = 'HOME';

    /**
     * @param list<string> $characteristics Human-readable details given by Boxtal (delays, liability, …), in French
     */
    public function __construct(
        public string $carrierCode,
        public string $carrierName,
        public string $serviceCode,
        public string $serviceName,
        public float $priceExclTax,
        public float $priceInclTax,
        public string $currency,
        public string $collectionType,
        public ?\DateTimeImmutable $collectionDate,
        public string $deliveryType,
        public ?\DateTimeImmutable $deliveryDate,
        public string $logoUrl = '',
        public array $characteristics = [],
    ) {
    }

    /** The code to order this offer with (v3 shippingOfferCode), e.g. "CHRP-ChronoShoptoShop" */
    public function code(): string
    {
        return $this->carrierCode.'-'.$this->serviceCode;
    }

    public function deliversToPickupPoint(): bool
    {
        return self::DELIVERY_PICKUP_POINT === $this->deliveryType;
    }

    public function deliversHome(): bool
    {
        return self::DELIVERY_HOME === $this->deliveryType;
    }

    /** Days between the collection and the delivery dates, when both are known */
    public function transitDays(): ?int
    {
        if (null === $this->collectionDate || null === $this->deliveryDate) {
            return null;
        }

        return (int) $this->collectionDate->diff($this->deliveryDate)->days;
    }

    public static function fromXml(\SimpleXMLElement $offer): self
    {
        $date = static function (?\SimpleXMLElement $node): ?\DateTimeImmutable {
            $value = trim((string) $node);

            return '' === $value ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null);
        };
        $characteristics = [];
        foreach ($offer->characteristics->label ?? [] as $label) {
            $characteristics[] = trim((string) $label);
        }

        return new self(
            carrierCode: (string) $offer->operator->code,
            carrierName: (string) $offer->operator->label,
            serviceCode: (string) $offer->service->code,
            serviceName: (string) $offer->service->label,
            priceExclTax: (float) $offer->price->{'tax-exclusive'},
            priceInclTax: (float) $offer->price->{'tax-inclusive'},
            currency: (string) ($offer->price->currency ?? 'EUR') ?: 'EUR',
            collectionType: (string) $offer->collection->type->code,
            collectionDate: $date($offer->collection->date),
            deliveryType: (string) $offer->delivery->type->code,
            deliveryDate: $date($offer->delivery->date),
            logoUrl: (string) $offer->operator->logo,
            characteristics: $characteristics,
        );
    }
}
