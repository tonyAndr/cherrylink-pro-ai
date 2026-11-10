<?php

declare(strict_types=1);

namespace CherryLink\Indexing\Outbox;

/**
 * Raised when the authoritative post row could not be read.
 *
 * Deliberately distinct from "the post does not exist": a read failure must not
 * be interpreted as a deletion, because that would tombstone a live article in
 * the index. Callers fail closed (keep the dirty marker / retry).
 */
final class CaptureReadException extends \RuntimeException
{
}
