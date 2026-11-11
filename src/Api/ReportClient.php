<?php

declare(strict_types=1);

namespace CherryLink\Api;

/** Narrow client contract used by the background plugin-report sender. */
interface ReportClient
{
    /**
     * @param array<int,array<string,mixed>> $events
     * @return array<string,mixed>|null
     */
    public function report_plugin_events(array $events): ?array;
}
