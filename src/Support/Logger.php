<?php

declare(strict_types=1);

namespace CherryLink\Support;

/**
 * Debug logger. Writes to the PHP error log only when WP_DEBUG is on — a verbatim
 * port of the legacy cherry_write_log() (now a back-compat shim in cherrylink_main.php).
 *
 * The one intentional hardening over the original: we guard on defined('WP_DEBUG')
 * so analysis/CLI contexts where the constant is absent don't fatal. In every real
 * WordPress request WP_DEBUG is always defined, so the observable behavior is identical.
 */
class Logger
{
    /**
     * @param mixed $data Scalar, array, or object — arrays/objects are print_r'd.
     */
    public function log($data): void
    {
        if (!\defined('WP_DEBUG') || true !== \WP_DEBUG) {
            return;
        }

        if (\is_array($data) || \is_object($data)) {
            \error_log(\print_r($data, true));
        } else {
            \error_log($data);
        }
    }
}
