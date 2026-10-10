<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Shipping;

/**
 * A document of a shipping order: the label (LABEL) and, for some shipments, PROFORMA, CN23 or VOUCHER.
 * The URL is signed and expires: download the file rather than keeping the link for long.
 */
final readonly class ShippingDocument
{
    public const LABEL = 'LABEL';

    public function __construct(
        public string $type,
        public string $format,
        public string $url,
    ) {
    }

    public function isLabel(): bool
    {
        return self::LABEL === $this->type;
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['type'] ?? ''), (string) ($data['format'] ?? ''), (string) ($data['url'] ?? ''));
    }
}
