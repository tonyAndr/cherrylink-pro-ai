<?php
/*
 * CherryLink Plugin
 */

// Disable direct access
defined('ABSPATH') || exit;
// Define lib name
define('LP_ADMIN_SUBPAGES_LIBRARY', true);

class lp_admin_subpages
{
    var $pages = array();
    var $parent_page = '';
    var $current_page = array();

    function __construct($parent_page = '')
    {
        if ($parent_page === '') {
            $parent_page = $_SERVER['QUERY_STRING'];
            $p1 = strpos($parent_page, 'page=');
            $p2 = strpos($parent_page, '&');
            if ($p2 === false) {
                $parent_page = substr($parent_page, $p1 + 5);
            } else {
                $parent_page = substr($parent_page, $p1 + 5, $p2 - $p1 - 5);
            }
        }
        $this->parent_page = $parent_page;
    }

    function add_subpage($title, $slug, $view)
    {
        $this->pages[] = array('title' => $title, 'slug' => $slug, 'view' => $view);
    }

    function add_subpages($pages)
    {
        foreach ($pages as $page) {
            $this->pages[] = array('title' => $page[0], 'slug' => $page[1], 'view' => $page[2]);
        }
    }

    function page_from_slug($slug)
    {
        if (!isset($slug) || !$slug) {
            return $this->pages[0];
        }
        foreach ($this->pages as $page) {
            if ($page['slug'] === $slug) {
                return $page;
            }
        }
        die('non-existent slug');
    }

    function display_menu()
    {
        // for compatibility with WP mu
        $base = isset($_SERVER['REDIRECT_URL'])
            ? sanitize_text_field(wp_unslash($_SERVER['REDIRECT_URL']))
            : (isset($_SERVER['PHP_SELF']) ? sanitize_text_field(wp_unslash($_SERVER['PHP_SELF'])) : '');
        $base .= '?page=' . $this->parent_page . '&subpage=';
        $subpage = isset($_GET['subpage']) ? sanitize_text_field(wp_unslash($_GET['subpage'])) : false;
        $this->current_page = $subpage ? $this->page_from_slug($subpage) : $this->page_from_slug(false);

        $items = array();
        foreach ($this->pages as $page) {
            // Hide link stats tab
            if ($page['slug'] === 'statistics') {
                continue;
            }
            $items[] = array(
                'title'   => $page['title'],
                'url'     => $base . $page['slug'],
                'current' => $page === $this->current_page,
            );
        }

        echo (new \CherryLink\Support\View())->render('admin/subpage-menu', array('items' => $items));
    }

    function display_view()
    {
        $this->current_page['view']();
    }

    function display()
    {
        $this->display_menu();
        $this->display_view();
    }
}
