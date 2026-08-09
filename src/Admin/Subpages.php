<?php

declare(strict_types=1);

namespace CherryLink\Admin;

use CherryLink\Support\View;

/**
 * Tabbed subpage host for the settings screen.
 *
 * Ported from the legacy lp_admin_subpages god-class. Each subpage is a title +
 * URL slug + a view callable; display() renders the tab menu (via the
 * admin/subpage-menu template) then invokes the current tab's view. The current
 * tab comes from ?subpage=<slug>, defaulting to the first registered page.
 */
final class Subpages
{
    /** @var array<int, array{title: string, slug: string, view: callable}> */
    private $pages = [];

    /** @var string */
    private $parentPage;

    /** @var array{title: string, slug: string, view: callable}|null */
    private $currentPage = null;

    public function __construct(string $parentPage = '')
    {
        if ($parentPage === '') {
            $qs         = isset($_SERVER['QUERY_STRING'])
                ? \sanitize_text_field(\wp_unslash($_SERVER['QUERY_STRING']))
                : '';
            $p1         = \strpos($qs, 'page=');
            $p2         = \strpos($qs, '&');
            $parentPage = $p1 === false
                ? $qs
                : ($p2 === false ? \substr($qs, $p1 + 5) : \substr($qs, $p1 + 5, $p2 - $p1 - 5));
        }
        $this->parentPage = $parentPage;
    }

    public function addSubpage(string $title, string $slug, callable $view): void
    {
        $this->pages[] = ['title' => $title, 'slug' => $slug, 'view' => $view];
    }

    /**
     * @return array{title: string, slug: string, view: callable}
     */
    private function pageFromSlug(?string $slug): array
    {
        if (! $slug) {
            return $this->pages[0];
        }
        foreach ($this->pages as $page) {
            if ($page['slug'] === $slug) {
                return $page;
            }
        }
        die('non-existent slug');
    }

    public function displayMenu(): void
    {
        // for compatibility with WP mu
        $base = isset($_SERVER['REDIRECT_URL'])
            ? \sanitize_text_field(\wp_unslash($_SERVER['REDIRECT_URL']))
            : (isset($_SERVER['PHP_SELF']) ? \sanitize_text_field(\wp_unslash($_SERVER['PHP_SELF'])) : '');
        $base   .= '?page=' . $this->parentPage . '&subpage=';
        $subpage = isset($_GET['subpage']) ? \sanitize_text_field(\wp_unslash($_GET['subpage'])) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector
        $this->currentPage = $subpage ? $this->pageFromSlug($subpage) : $this->pageFromSlug(null);

        $items = [];
        foreach ($this->pages as $page) {
            // Hide link stats tab
            if ($page['slug'] === 'statistics') {
                continue;
            }
            $items[] = [
                'title'   => $page['title'],
                'url'     => $base . $page['slug'],
                'current' => $page === $this->currentPage,
            ];
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- View renders pre-escaped template output
        echo (new View())->render('admin/subpage-menu', ['items' => $items]);
    }

    public function displayView(): void
    {
        if ($this->currentPage !== null) {
            \call_user_func($this->currentPage['view']);
        }
    }

    public function display(): void
    {
        $this->displayMenu();
        $this->displayView();
    }
}
