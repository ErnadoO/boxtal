<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Quote;

/**
 * A quote request (v1 API, GET /cotation): one parcel, from a place to another.
 *
 * Boxtal only needs the post code, city and country of each side; prices depend on the weight (and on the
 * dimensions for offers priced on volumetric weight) and on the departure and arrival places.
 */
final readonly class QuoteRequest
{
    /**
     * @param string                  $contentCode   5-digit content code (30100 = cosmetics), see BoxtalClient::contentCategories()
     * @param list<string>            $offerCodes    Only these offers ("MONR-CpourToi", "POFR-ColissimoAccess"…), all when empty
     * @param \DateTimeInterface|null $collectionDate Desired drop-off / collection date, today when null
     */
    public function __construct(
        public string $fromPostalCode,
        public string $fromCity,
        public string $toPostalCode,
        public string $toCity,
        public float $weightKg,
        public int $lengthCm,
        public int $widthCm,
        public int $heightCm,
        public string $contentCode = '30100',
        public string $fromCountryCode = 'FR',
        public string $toCountryCode = 'FR',
        public bool $fromIndividual = true,
        public bool $toIndividual = true,
        public ?float $valueEur = null,
        public array $offerCodes = [],
        public ?\DateTimeInterface $collectionDate = null,
    ) {
        if ($weightKg <= 0) {
            throw new \InvalidArgumentException('The parcel weight must be greater than 0 kg.');
        }
        if ($lengthCm <= 0 || $widthCm <= 0 || $heightCm <= 0) {
            throw new \InvalidArgumentException('The parcel dimensions must be greater than 0 cm.');
        }
    }

    /**
     * Query parameters of GET /cotation.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [
            'colis_1.poids' => self::number($this->weightKg),
            'colis_1.longueur' => (string) $this->lengthCm,
            'colis_1.largeur' => (string) $this->widthCm,
            'colis_1.hauteur' => (string) $this->heightCm,
            'code_contenu' => $this->contentCode,
            'expediteur.pays' => $this->fromCountryCode,
            'expediteur.code_postal' => $this->fromPostalCode,
            'expediteur.ville' => $this->fromCity,
            'expediteur.type' => $this->fromIndividual ? 'particulier' : 'entreprise',
            'destinataire.pays' => $this->toCountryCode,
            'destinataire.code_postal' => $this->toPostalCode,
            'destinataire.ville' => $this->toCity,
            'destinataire.type' => $this->toIndividual ? 'particulier' : 'entreprise',
        ];
        if (null !== $this->valueEur) {
            $query['colis.valeur'] = self::number($this->valueEur);
        }
        if (null !== $this->collectionDate) {
            $query['collecte'] = $this->collectionDate->format('Y-m-d');
        }
        // The v1 API wants the carrier and service codes glued together: "MONR-CpourToi" → "MONRCpourToi"
        foreach ($this->offerCodes as $i => $code) {
            $query[sprintf('offers[%d]', $i)] = str_replace('-', '', $code);
        }

        return $query;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
    }
}
