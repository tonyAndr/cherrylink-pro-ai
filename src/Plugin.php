<?php

declare(strict_types=1);

namespace CherryLink;

use CherryLink\Contracts\Registerable;

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

    private function __construct(string $file)
    {
        $this->file = $file;
        $this->dir  = \plugin_dir_path($file);
        $this->url  = \plugin_dir_url($file);
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
}
