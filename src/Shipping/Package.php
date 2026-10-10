<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Shipping;

/**
 * One parcel of a shipping order. The content id comes from BoxtalClient::contentCategories()
 * ("content:v1:30100" = cosmetics, well-being).
 */
final readonly class Package
{
    public function __construct(
        public float $weightKg,
        public int $lengthCm,
        public int $widthCm,
        public int $heightCm,
        public float $valueEur,
        public string $contentDescription,
        public string $contentId = 'content:v1:30100',
        public ?string $externalId = null,
    ) {
        if ($weightKg <= 0) {
            throw new \InvalidArgumentException('The parcel weight must be greater than 0 kg.');
        }
        if ($lengthCm <= 0 || $widthCm <= 0 || $heightCm <= 0) {
            throw new \InvalidArgumentException('The parcel dimensions must be greater than 0 cm.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $package = [
            'type' => 'PARCEL',
            'weight' => $this->weightKg,
            'length' => $this->lengthCm,
            'width' => $this->widthCm,
            'height' => $this->heightCm,
            'value' => ['value' => $this->valueEur, 'currency' => 'EUR'],
            'content' => ['id' => $this->contentId, 'description' => $this->contentDescription],
        ];
        if (null !== $this->externalId) {
            $package['externalId'] = $this->externalId;
        }

        return $package;
    }
}
