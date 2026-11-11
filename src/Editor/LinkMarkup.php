<?php

declare(strict_types=1);

namespace CherryLink\Editor;

/**
 * Renders the open/close link markup of the unified panel from the stored link
 * templates (link_before / link_after / term_before / term_after: base64(urlencode(html))
 * with {tags}) plus the relative-link setting (via LinkFields::url).
 */
final class LinkMarkup
{
    private const TAGS = ['url', 'title', 'categorynames', 'date', 'author', 'postid', 'imagesrc', 'anons'];

    /**
     * @param array<string,mixed> $options linkate-posts options
     * @param \stdClass           $result  post-like object (ID, post_title, post_author, post_date, post_excerpt, post_content)
     * @return array{open:string,close:string}
     */
    public static function forPost(array $options, $result, string $option_key = 'linkate-posts'): array
    {
        $before = self::decode($options['link_before'] ?? '');
        $after  = self::decode($options['link_after'] ?? '');
        $values = [];
        foreach ([$before, $after] as $tpl) {
            foreach (self::TAGS as $tag) {
                if (! isset($values[$tag]) && \strpos($tpl, '{' . $tag . '}') !== false) {
                    $values[$tag] = self::value($tag, $option_key, $result);
                }
            }
        }
        return ['open' => self::fill($before, $values), 'close' => self::fill($after, $values)];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{open:string,close:string}
     */
    public static function forTerm(array $options, string $url, string $title, string $taxonomy): array
    {
        $values = [
            'url'      => $url,
            'title'    => \htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            'taxonomy' => $taxonomy,
        ];
        return [
            'open'  => self::fill(self::decode($options['term_before'] ?? ''), $values),
            'close' => self::fill(self::decode($options['term_after'] ?? ''), $values),
        ];
    }

    /** Plain anchor for Gutenberg-serialized content (no templates). */
    public static function plain(string $url, string $title): array
    {
        $open = '<a href="' . \esc_url($url) . '"' . ($title !== '' ? ' title="' . \esc_attr($title) . '"' : '') . '>';
        return ['open' => $open, 'close' => '</a>'];
    }

    /** @param mixed $stored */
    private static function decode($stored): string
    {
        if (! \is_string($stored) || $stored === '') {
            return '';
        }
        $raw = \base64_decode($stored, true);
        return $raw === false ? '' : \urldecode($raw);
    }

    /** @param array<string,string> $values */
    private static function fill(string $tpl, array $values): string
    {
        $map = [];
        foreach ($values as $k => $v) {
            $map['{' . $k . '}'] = $v;
        }
        return \strtr($tpl, $map);
    }

    /** @param \stdClass $result */
    private static function value(string $tag, string $option_key, $result): string
    {
        switch ($tag) {
            case 'url':
                return LinkFields::url($option_key, $result);
            case 'title':
                return LinkFields::title($option_key, $result);
            case 'categorynames':
                return LinkFields::categoryNames($option_key, $result);
            case 'date':
                return LinkFields::date($option_key, $result);
            case 'author':
                return LinkFields::author($option_key, $result);
            case 'postid':
                return (string) LinkFields::postId($option_key, $result);
            case 'imagesrc':
                return LinkFields::imageSrc($option_key, $result);
            case 'anons':
                return LinkFields::anons($option_key, $result);
        }
        return '';
    }
}
