<?php

declare(strict_types=1);

namespace CherryLink\Indexing\Outbox;

/**
 * Raised when an outbox operation cannot be completed safely (e.g. the schema is
 * missing and cannot be created, or an insert fails without an idempotent replay).
 *
 * Callers should treat this as fail-closed: the event was NOT durably accepted, so
 * they must keep their dirty marker / retry path instead of silently dropping work.
 */
final class OutboxException extends \RuntimeException
{
}
