<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Shipping;

/**
 * Sender or recipient of a shipping order: contact and location.
 *
 * Boxtal requires a valid e-mail (on a real domain: ".test" is refused) and a phone number.
 */
final readonly class Address
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $phone,
        public string $street,
        public string $postalCode,
        public string $city,
        public string $countryIsoCode = 'FR',
        public ?string $number = null,
        public bool $residential = true,
        public ?string $company = null,
        public ?string $additionalInformation = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $contact = ['firstName' => $this->firstName, 'lastName' => $this->lastName, 'email' => $this->email, 'phone' => $this->phone];
        if (null !== $this->company && '' !== $this->company) {
            $contact['company'] = $this->company;
        }
        $location = ['street' => $this->street, 'postalCode' => $this->postalCode, 'city' => $this->city, 'countryIsoCode' => $this->countryIsoCode];
        if (null !== $this->number && '' !== $this->number) {
            $location['number'] = $this->number;
        }
        $address = ['type' => $this->residential ? 'RESIDENTIAL' : 'BUSINESS', 'contact' => $contact, 'location' => $location];
        if (null !== $this->additionalInformation && '' !== $this->additionalInformation) {
            $address['additionalInformation'] = $this->additionalInformation;
        }

        return $address;
    }
}
