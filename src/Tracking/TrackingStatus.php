<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Tracking;

/**
 * Tracking statuses, harmonised by Boxtal across carriers.
 */
enum TrackingStatus: string
{
    /** Label created, parcel not yet handed to the carrier */
    case Announced = 'ANNOUNCED';
    case Shipped = 'SHIPPED';
    case InTransit = 'IN_TRANSIT';
    case OutForDelivery = 'OUT_FOR_DELIVERY';
    case FailedAttempt = 'FAILED_ATTEMPT';
    case ReachedDeliveryPickupPoint = 'REACHED_DELIVERY_PICKUP_POINT';
    case Delivered = 'DELIVERED';
    case Returned = 'RETURNED';
    case Exception = 'EXCEPTION';

    /** The carrier has the parcel (or had it): it was dropped off */
    public function isHandedOver(): bool
    {
        return self::Announced !== $this;
    }
}
