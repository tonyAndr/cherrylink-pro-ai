<?php

declare(strict_types=1);

namespace CherryLink\Indexing\Outbox;

/**
 * Raised when the post changed between allocating the source revision and
 * reading it, so no payload could be captured that provably matches the
 * revision. Callers fail closed (keep the index-dirty marker) rather than
 * enqueue stale content that could overwrite a newer revision.
 */
final class StaleCaptureException extends \RuntimeException
{
}
