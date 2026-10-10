<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\ParcelPoint;

/**
 * Whether parcel points are searched for the departure (drop-off by the sender) or the arrival (pickup by the
 * recipient) of a shipment.
 */
enum Operation: string
{
    case Departure = 'DEPARTURE';
    case Arrival = 'ARRIVAL';
}
