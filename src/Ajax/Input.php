<?php

declare(strict_types=1);

namespace CherryLink\Ajax;

/**
 * Typed sanitization of request input, applied at read time.
 *
 * Handlers never touch raw $_POST/$_GET values: they ask for a field by name and type
 * (or hand over an already decoded JSON body plus a field => type schema) and get back a
 * clean value. Unknown fields are dropped, non-scalar values collapse to the type's empty
 * value, and arrays are sanitized element by element.
 *
 * Types: int (signed), uint (absint), bool, key (sanitize_key), text (sanitize_text_field),
 * textarea (sanitize_textarea_field), url (esc_url_raw), html (wp_kses_post).
 */
final class Input
{
    /**
     * Sanitize one value by type; arrays are mapped element-wise.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function clean($value, string $type)
    {
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $k => $item) {
                $out[\is_int($k) ? $k : \sanitize_key((string) $k)] = self::clean($item, $type);
            }
            return $out;
        }
        if (! \is_scalar($value)) {
            $value = '';
        }
        switch ($type) {
            case 'int':
                return (int) $value;
            case 'uint':
                return \absint($value);
            case 'bool':
                return (bool) \rest_sanitize_boolean((string) $value);
            case 'key':
                return \sanitize_key((string) $value);
            case 'textarea':
                return \sanitize_textarea_field((string) $value);
            case 'url':
                return \esc_url_raw((string) $value);
            case 'html':
                return \wp_kses_post((string) $value);
            case 'text':
            default:
                return \sanitize_text_field((string) $value);
        }
    }

    /**
     * Keep only the schema's fields (those present in $data), each sanitized by type.
     *
     * @param array<string,mixed>  $data   decoded request body
     * @param array<string,string> $schema field => type
     * @return array<string,mixed>
     */
    public static function fields(array $data, array $schema): array
    {
        $out = [];
        foreach ($schema as $field => $type) {
            if (\array_key_exists($field, $data)) {
                $out[$field] = self::clean($data[$field], $type);
            }
        }
        return $out;
    }

    /**
     * One $_POST field, sanitized. Call only after Security::verify() (or an equivalent
     * nonce + capability check) in the same handler.
     *
     * @param mixed $default
     * @return mixed
     */
    public static function post(string $key, string $type = 'text', $default = '')
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify the nonce first
        if (! isset($_POST[$key])) {
            return $default;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by clean()
        return self::clean(\wp_unslash($_POST[$key]), $type);
    }

    /**
     * One $_GET field, sanitized. For read-only screen selectors and nonce-checked links.
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, string $type = 'text', $default = '')
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selector; state changes verify their own nonce
        if (! isset($_GET[$key])) {
            return $default;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by clean()
        return self::clean(\wp_unslash($_GET[$key]), $type);
    }
}
