<?php

/**
 * Inert stubs for WordPress functions that legacy plugin files call at include time
 * (hook registration, scheduling) or that are pure passthroughs (translation/escaping).
 *
 * These are deliberately NOT Brain Monkey mocks: they must exist before any test file
 * requires a legacy source file. Anything a test needs to assert on (get_option,
 * update_option, get_post_meta, …) is intentionally absent here and mocked per-test.
 */

// ── Hook + scheduling registration (called while requiring legacy files) ──────────
if (!function_exists('add_action')) {
    function add_action(...$args)
    {
        return true;
    }
}
if (!function_exists('add_filter')) {
    function add_filter(...$args)
    {
        return true;
    }
}
if (!function_exists('remove_action')) {
    function remove_action(...$args)
    {
        return true;
    }
}
if (!function_exists('remove_filter')) {
    function remove_filter(...$args)
    {
        return true;
    }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook(...$args)
    {
    }
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(...$args)
    {
    }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(...$args)
    {
        return false;
    }
}
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event(...$args)
    {
        return true;
    }
}

// ── Translation + escaping passthroughs ───────────────────────────────────────────
if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null)
    {
        return $text;
    }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = null)
    {
        return $text;
    }
}
if (!function_exists('_x')) {
    function _x($text, $context, $domain = null)
    {
        return $text;
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return $text;
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return $text;
    }
}
if (!function_exists('esc_url')) {
    function esc_url($text)
    {
        return $text;
    }
}
