<?php

declare(strict_types=1);

namespace CherryLink\Indexing;

/**
 * Raised when the published-post inventory cannot be read.
 *
 * Fail closed: an empty result must never be interpreted as "the site has no
 * posts", because that would declare an empty snapshot or plan a delete of the
 * entire index.
 */
final class InventoryUnavailableException extends \RuntimeException
{
}
