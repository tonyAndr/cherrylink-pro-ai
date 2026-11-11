<?php

declare(strict_types=1);

namespace CherryLink\Support;

/**
 * Resolves the content language code (lowercase, two letters) sent to the API.
 *
 * Order: Polylang, WPML, site locale, then 'ru' (the historical default).
 */
final class Language
{
    private const FALLBACK = 'ru';

    public static function forPost(int $post_id): string
    {
        if (\function_exists('pll_get_post_language')) {
            $code = self::normalize(\pll_get_post_language($post_id, 'slug'));
            if ($code !== '') {
                return $code;
            }
        }

        if (\function_exists('apply_filters')) {
            $details = \apply_filters('wpml_post_language_details', null, $post_id);
            if (\is_array($details) && isset($details['language_code'])) {
                $code = self::normalize($details['language_code']);
                if ($code !== '') {
                    return $code;
                }
            }
        }

        return self::forSite();
    }

    public static function forSite(): string
    {
        $locale = \function_exists('get_locale') ? \get_locale() : '';
        $code   = self::normalize($locale);
        return $code !== '' ? $code : self::FALLBACK;
    }

    /** @param mixed $value */
    private static function normalize($value): string
    {
        if (!\is_string($value)) {
            return '';
        }
        $code = \strtolower(\substr(\trim($value), 0, 2));
        return \preg_match('/^[a-z]{2}$/', $code) === 1 ? $code : '';
    }
}
