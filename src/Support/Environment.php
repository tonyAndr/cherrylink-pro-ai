<?php

declare(strict_types=1);

namespace CherryLink\Support;

/**
 * Safe, bounded server/WordPress facts for plugin lifecycle reports.
 *
 * Deliberately does not reuse the debug W18T class: that opens its own mysqli
 * connection and reads unguarded server globals. Here every value is optional,
 * control characters are stripped, and only short allowlisted strings leave the
 * site. Never add paths, database credentials, keys, IPs, or free-form text.
 */
final class Environment
{
    /**
     * @return array{wp_version:string,php_version:string,db_version:string,web_server:string,os:string,theme_name:string,theme_version:string,locale:string,multisite:bool}
     */
    public static function collect(): array
    {
        global $wpdb;

        $theme = \function_exists('wp_get_theme') ? \wp_get_theme() : null;
        $wpVersion = \function_exists('get_bloginfo')
            ? (string) \get_bloginfo('version')
            : (string) ($GLOBALS['wp_version'] ?? '');
        $dbVersion = '';
        if (\is_object($wpdb) && \method_exists($wpdb, 'db_version')) {
            $dbVersion = (string) $wpdb->db_version();
        }

        return self::normalize([
            'wp_version'     => $wpVersion,
            'php_version'    => PHP_VERSION,
            'db_version'     => $dbVersion,
            'web_server'     => self::webServer(),
            'os'             => self::operatingSystem(),
            'theme_name'     => \is_object($theme) ? (string) $theme->get('Name') : '',
            'theme_version'  => \is_object($theme) ? (string) $theme->get('Version') : '',
            'locale'         => \function_exists('get_locale') ? (string) \get_locale() : '',
            'multisite'      => \function_exists('is_multisite') ? (bool) \is_multisite() : false,
        ]);
    }

    /**
     * Re-validate a stored snapshot so a corrupted option cannot put arbitrary
     * text into a report.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function normalize(array $data): array
    {
        $limits = [
            'wp_version'    => 40,
            'php_version'   => 40,
            'db_version'    => 80,
            'web_server'    => 120,
            'os'            => 120,
            'theme_name'    => 120,
            'theme_version' => 40,
            'locale'        => 40,
        ];
        $out = [];
        foreach ($limits as $key => $limit) {
            $value = $data[$key] ?? '';
            $out[$key] = \is_string($value) ? self::text($value, $limit) : '';
        }
        $out['multisite'] = ! empty($data['multisite']);

        return $out;
    }

    private static function webServer(): string
    {
        $server = isset($_SERVER['SERVER_SOFTWARE']) && \is_string($_SERVER['SERVER_SOFTWARE'])
            ? \sanitize_text_field(\wp_unslash($_SERVER['SERVER_SOFTWARE']))
            : '';
        if ($server !== '') {
            return $server;
        }

        return \function_exists('php_sapi_name') ? (string) \php_sapi_name() : '';
    }

    private static function operatingSystem(): string
    {
        $family  = \defined('PHP_OS_FAMILY') ? (string) PHP_OS_FAMILY : PHP_OS;
        $release = \function_exists('php_uname') ? (string) \php_uname('r') : '';

        return \trim($family . ' ' . $release);
    }

    /** Strip control characters (including newlines), trim, and bound by characters. */
    private static function text(string $value, int $limit): string
    {
        $value = (string) \preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        $value = \trim($value);
        if ($value === '') {
            return '';
        }
        if (\function_exists('mb_substr')) {
            return \mb_substr($value, 0, $limit, 'UTF-8');
        }

        return \substr($value, 0, $limit);
    }
}
