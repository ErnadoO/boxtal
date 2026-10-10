<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\ParcelPoint;

/**
 * A parcel point (relay point, locker, post office…) near a searched place.
 *
 * `code` is what a shipping order needs (pickupPointCode / dropoffPointCode). One point can belong to several
 * networks (`networks`, e.g. MONR_NETWORK and MONR_LOCKERS_ONLY_NETWORK).
 */
final readonly class ParcelPoint
{
    /**
     * @param list<string>                                                      $networks
     * @param array<string, list<array{opening: string, closing: string}>>|null $openingHours By day (MONDAY…SUNDAY); a missing day is
     *                                                                                        closed. Null when the network gives no
     *                                                                                        hours at all (Colissimo: every day null)
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $street,
        public string $postalCode,
        public string $city,
        public string $countryIsoCode,
        public ?float $latitude,
        public ?float $longitude,
        public array $networks,
        public ?array $openingHours,
        public ?int $distanceMeters = null,
    ) {
    }

    public function belongsTo(string $network): bool
    {
        return \in_array($network, $this->networks, true);
    }

    /** False when the opening hours are unknown, not when the point is closed */
    public function hasOpeningHours(): bool
    {
        return null !== $this->openingHours;
    }

    /** Lockers are flagged by their network (…_LOCKERS_ONLY_NETWORK) */
    public function isLocker(): bool
    {
        foreach ($this->networks as $network) {
            if (str_contains($network, 'LOCKERS_ONLY')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $item One element of "content": {distanceFromSearchLocation, parcelPoint}
     */
    public static function fromArray(array $item): self
    {
        $point = (array) ($item['parcelPoint'] ?? $item);
        $location = (array) ($point['location'] ?? []);
        $position = (array) ($location['position'] ?? []);
        $hours = [];
        $known = false;
        foreach ((array) ($point['openingDays'] ?? []) as $day => $periods) {
            // null: unknown; []: closed that day
            $known = $known || null !== $periods;
            foreach ((array) $periods as $period) {
                if (\is_array($period) && isset($period['openingTime'], $period['closingTime'])) {
                    $hours[(string) $day][] = ['opening' => (string) $period['openingTime'], 'closing' => (string) $period['closingTime']];
                }
            }
        }

        return new self(
            code: (string) ($point['code'] ?? ''),
            name: self::text($point['name'] ?? ''),
            // Some addresses come on two lines ("20 PLACE DU MARCHE SAINT-HONORE\nSAINT…")
            street: self::text($location['street'] ?? ''),
            postalCode: (string) ($location['postalCode'] ?? ''),
            city: self::text($location['city'] ?? ''),
            countryIsoCode: (string) ($location['countryIsoCode'] ?? ''),
            latitude: isset($position['latitude']) ? (float) $position['latitude'] : null,
            longitude: isset($position['longitude']) ? (float) $position['longitude'] : null,
            networks: array_values(array_map('strval', (array) ($point['compatibleNetworks'] ?? []))),
            openingHours: $known ? $hours : null,
            distanceMeters: isset($item['distanceFromSearchLocation']) ? (int) $item['distanceFromSearchLocation'] : null,
        );
    }

    private static function text(mixed $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim((string) $value));
    }
}
