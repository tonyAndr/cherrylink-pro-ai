<?php

declare(strict_types=1);

namespace CherryLink;

use CherryLink\Contracts\Registerable;
use CherryLink\Support\Options;

/**
 * Plugin bootstrap / lightweight service container.
 *
 * Constructed once from the entry file (Plugin::boot(__FILE__)). It holds the
 * plugin's file/dir/url and wires services: each service is a Registerable that
 * hooks itself in register(). Services are migrated here one at a time across the
 * refactor stages; until then the legacy procedural files register their own hooks.
 */
final class Plugin
{
    /** @var Plugin|null */
    private static $instance = null;

    /** @var string */
    private $file;

    /** @var string */
    private $dir;

    /** @var string */
    private $url;

    /** @var Registerable[] */
    private $services = [];

    /** @var Options */
    private $options;

    private function __construct(string $file)
    {
        $this->file    = $file;
        $this->dir     = \plugin_dir_path($file);
        $this->url     = \plugin_dir_url($file);
        $this->options = new Options();
    }

    /**
     * Boot the plugin once. Idempotent: subsequent calls return the same instance.
     */
    public static function boot(string $file): self
    {
        if (self::$instance === null) {
            self::$instance = new self($file);
            self::$instance->registerServices();
        }

        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('CherryLink\\Plugin has not been booted yet.');
        }

        return self::$instance;
    }

    private function registerServices(): void
    {
        // Namespaced services migrated out of the legacy procedural files. Each is a
        // Registerable that wires its own WordPress hooks (guarding on admin/license/
        // screen exactly as the old file-scope code did). Construction is cheap and
        // side-effect-free; the actual API/DB work happens inside the hook callbacks
        // at request time, so boot order relative to the legacy require chain is safe.
        // API runtime constants (URL + relay) must exist before any Api\Client is built.
        \CherryLink\Api\Relay::defineConstants();

        // Shared license service — injected into the indexing services (which gate
        // API work on a valid license) and registered in its own right below.
        $license = new \CherryLink\Support\License($this->options);

        $this->services = [
            new \CherryLink\Api\Relay(),
            new \CherryLink\Admin\StatsColumn(),
            new \CherryLink\Admin\AdminAssets($this->options),
            new \CherryLink\Admin\Notices(),
            new \CherryLink\Editor\GutenbergAssets($this->options),
            new \CherryLink\Indexing\IndexHooks($this->options, $license),
            new \CherryLink\Indexing\SyncService($this->options, $license),
            new \CherryLink\Terms\TermsService(),
            new \CherryLink\Statistics\StatisticsService(),
            new \CherryLink\Csv\CsvService(),
            new \CherryLink\Editor\ClassicPanel($this->options),
            new \CherryLink\Debug\Debugger(),
            $license,
            new \CherryLink\Support\Lifecycle($license),
            new \CherryLink\Admin\SettingsPage(),
        ];

        foreach ($this->services as $service) {
            $service->register();
        }
    }

    public function file(): string
    {
        return $this->file;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function url(): string
    {
        return $this->url;
    }

    /** Shared settings repository, injected into services as they migrate here. */
    public function options(): Options
    {
        return $this->options;
    }
}
