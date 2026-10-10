<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Shipping;

enum ShippingOrderStatus: string
{
    /** Just placed, being sent to the carrier */
    case Pending = 'PENDING';
    case Requested = 'REQUESTED';
    /** Accepted by the carrier: the label is (or will soon be) available */
    case Confirmed = 'CONFIRMED';
    /** Cancelled by you, or by Boxtal 30 days after the label when the parcel was never dropped off (refunded) */
    case Cancelled = 'CANCELLED';
}
