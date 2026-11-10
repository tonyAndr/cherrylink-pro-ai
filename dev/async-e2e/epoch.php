<?php
/** Print the plugin's durable source epoch (created on first use). */
use CherryLink\Indexing\Outbox\Repository;

$repo = new Repository();
echo $repo->sourceEpoch() . "\n";
