<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\ParcelPoint;

/**
 * Main parcel point networks, as named by Boxtal (seen on the test environment). Other values exist (weight limits,
 * lockers only…): searches accept any string.
 */
final class Network
{
    public const MONDIAL_RELAY = 'MONR_NETWORK';
    public const CHRONOPOST_SHOP = 'CHRP_SHOP_ONLY_NETWORK';
    public const COLISSIMO = 'POFR_NETWORK';
    public const RELAIS_COLIS = 'SOGP_NETWORK';
    public const COLIS_PRIVE = 'COPR_NETWORK';

    private function __construct()
    {
    }
}
