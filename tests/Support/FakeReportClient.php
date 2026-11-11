<?php

declare(strict_types=1);

namespace CherryLink\Tests\Support;

use CherryLink\Api\ReportClient;

/** In-memory API client double for PluginReports durability tests. */
final class FakeReportClient implements ReportClient
{
    /** @var array<int,array<int,array<string,mixed>>> */
    public $calls = [];

    /** @var array<string,mixed>|null */
    public $result = ['ok' => true];

    public function report_plugin_events(array $events): ?array
    {
        $this->calls[] = $events;
        return $this->result;
    }
}
