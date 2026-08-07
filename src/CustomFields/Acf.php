<?php

declare(strict_types=1);

namespace CherryLink\CustomFields;

/**
 * Settings-form post processing: merges a submitted settings form into the stored
 * options array, with the field-specific handling the CherryLink options need
 * (category-subtree expansion, status checkboxes, base64 template fields, import).
 *
 * Ported verbatim from cherrylink_acf.php (now a shim that keeps the link_cf_*
 * globals + the link_cf_display_* form-view emitters). The only caller of
 * optionsFromPost() is the settings page, which runs check_admin_referer() first;
 * the values are configuration stored as submitted (base64/HTML template fields
 * must NOT be sanitized), so the request-data reads carry a scoped sniff disable.
 */
final class Acf
{
    public static function isBase64Encoded(string $data): bool
    {
        return (bool) \preg_match('%^[a-zA-Z0-9/+]*={0,2}$%', $data);
    }

    public static function encodeURIComponent(string $str): string
    {
        $revert = ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')'];
        return \strtr(\rawurlencode($str), $revert);
    }

    /**
     * Merge the submitted form fields named in $args into $options.
     *
     * @param array<string,mixed> $options
     * @param array<int,string>   $args
     * @return array<string,mixed>
     */
    public static function optionsFromPost(array $options, array $args): array
    {
        // phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- settings save is gated by check_admin_referer() upstream; values are configuration stored verbatim (base64/HTML template fields must not be sanitized)
        foreach ($args as $arg) {
            switch ($arg) {
                case 'excluded_cats':
                    if (isset($_POST[$arg]) && ! empty($_POST[$arg])) {
                        // get the subcategories too (get_term_children exists >= WP 5.6)
                        $catarray = $_POST[$arg];
                        $catarray = \is_array($catarray) ? $catarray : \explode(',', $catarray);
                        foreach ($catarray as $cat) {
                            $catarray = \array_merge($catarray, \get_term_children((int) $cat, 'category'));
                        }
                        $options[$arg] = \implode(',', \array_unique($catarray));
                    } else {
                        $options[$arg] = '';
                    }
                    break;
                case 'excluded_authors':
                case 'show_customs':
                    if (isset($_POST[$arg]) && ! empty($_POST[$arg])) {
                        $options[$arg] = \is_array($_POST[$arg]) ? \implode(',', $_POST[$arg]) : $_POST[$arg];
                    } else {
                        $options[$arg] = '';
                    }
                    break;
                case 'status':
                    unset($options['status']);
                    if (isset($_POST['status']) && \is_array($_POST['status'])) {
                        $options['status']['publish'] = $_POST['status']['publish'];
                        $options['status']['private'] = $_POST['status']['private'];
                        $options['status']['draft']   = $_POST['status']['draft'];
                        $options['status']['future']  = $_POST['status']['future'];
                    } else {
                        $options['status']['publish'] = $_POST['status-publish'];
                        $options['status']['private'] = $_POST['status-private'];
                        $options['status']['draft']   = $_POST['status-draft'];
                        $options['status']['future']  = $_POST['status-future'];
                    }
                    break;
                case 'multilink':
                    $options[$arg] = isset($_POST[$arg]) ? 'checked' : '';
                    break;
                case 'link_before':
                case 'link_after':
                case 'term_before':
                case 'term_after':
                case 'link_temp_alt':
                case 'term_temp_alt':
                    $options[$arg] = self::isBase64Encoded($_POST[$arg])
                        ? $_POST[$arg]
                        : \base64_encode(\urlencode(\str_replace("'", '"', $_POST[$arg])));
                    break;
                case 'export':
                    $options = $_POST['export'];
                    \parse_str($_POST['export'], $options);
                    break;
                default:
                    $options[$arg] = \trim($_POST[$arg]);
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
        return $options;
    }
}
