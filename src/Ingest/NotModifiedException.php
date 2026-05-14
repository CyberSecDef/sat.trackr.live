<?php

declare(strict_types=1);

namespace SatTrackr\Ingest;

use RuntimeException;

/**
 * CelesTrak returns 403 with a "GP data has not updated since your last
 * successful download" body when you re-fetch a group it considers
 * unchanged. This is a polite throttling signal, not an actual error.
 */
final class NotModifiedException extends RuntimeException
{
}
