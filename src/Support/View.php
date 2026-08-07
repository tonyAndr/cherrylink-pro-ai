<?php

declare(strict_types=1);

namespace CherryLink\Support;

/**
 * Minimal template renderer. Loads a PHP partial from templates/ with a scoped
 * data array and returns its buffered output, keeping presentation out of the
 * service/controller layer.
 *
 * Two entry points:
 *  - render(name, data): buffer-and-return an HTML partial. The template file
 *    sees only the extracted $data keys (plus any FQN class/function it calls).
 *  - string(name): `return`-style partials that yield a raw string template
 *    (e.g. the strtr() link-card templates). No output buffering, no data.
 *
 * Templates apply their own escaping (esc_html/esc_attr/esc_url_raw). Values that
 * intentionally carry markup (link_before/link_after, pre-rendered card HTML) are
 * echoed raw by design — escaping is applied per value in the template, not here.
 */
final class View
{
    /** @var string absolute templates root, no trailing slash */
    private $base;

    public function __construct(?string $base = null)
    {
        if ($base !== null) {
            $this->base = \rtrim($base, '/');
        } elseif (\defined('CHERRYLINK_DIR')) {
            $this->base = \rtrim(\CHERRYLINK_DIR, '/') . '/templates';
        } else {
            $this->base = \dirname(__DIR__, 2) . '/templates';
        }
    }

    /**
     * Render an HTML partial and return its output.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->path($template);
        if (!\is_file($file)) {
            throw new \RuntimeException(\esc_html("CherryLink view not found: {$template}"));
        }

        // Isolate scope: a static closure keeps $this out, and the two internals
        // are passed as parameters under obscure names so real data keys (including
        // ones literally named "data" or "file") extract into scope uncollided.
        $render = static function (string $__cl_tpl, array $__cl_vars): string {
            \extract($__cl_vars, EXTR_SKIP);
            \ob_start();
            include $__cl_tpl;
            return (string) \ob_get_clean();
        };

        return $render($file, $data);
    }

    /**
     * Load a `return`-style string template (e.g. an strtr() placeholder string).
     */
    public function string(string $template): string
    {
        $file = $this->path($template);
        if (!\is_file($file)) {
            throw new \RuntimeException(\esc_html("CherryLink view not found: {$template}"));
        }

        return (string) include $file;
    }

    private function path(string $template): string
    {
        return $this->base . '/' . \ltrim($template, '/') . '.php';
    }
}
