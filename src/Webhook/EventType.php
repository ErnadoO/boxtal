<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Webhook;

enum EventType: string
{
    /** A document (label…) of a shipping order is available */
    case DocumentCreated = 'DOCUMENT_CREATED';
    /** The tracking of a shipping order changed */
    case TrackingChanged = 'TRACKING_CHANGED';
}
