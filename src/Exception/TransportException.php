<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Exception;

/**
 * Boxtal could not be reached, or answered something that is not a valid API response (network error, timeout,
 * unexpected body). Usually worth retrying later.
 */
final class TransportException extends BoxtalException
{
}
