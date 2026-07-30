<?php

declare(strict_types=1);

namespace CherryLink\Contracts;

/**
 * A service that wires its own WordPress hooks.
 *
 * The Plugin bootstrap constructs each service (injecting shared collaborators)
 * and calls register() once, during plugin load. Keeping add_action/add_filter
 * calls inside the owning class is the SOLID replacement for the old global
 * hook soup spread across the procedural files.
 *
 * NOTE: inert scaffolding as of Stage 0 — the PSR-4 autoloader and the Plugin
 * bootstrap that consume this land in Stage 1. It exists now so the QA tooling
 * (PHPCS/PHPStan) has real target code to prove the PSR-12 + PHP 7.4 setup.
 */
interface Registerable
{
    /**
     * Register this service's WordPress hooks.
     */
    public function register(): void;
}
