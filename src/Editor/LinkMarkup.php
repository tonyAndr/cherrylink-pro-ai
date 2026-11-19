<?php

declare(strict_types=1);

namespace CherryLink\Editor;

/**
 * The single link-markup builder (posts, terms, Gutenberg content): href, optional title,
 * optional new tab, optional class, driven by three settings. No free-form templates.
 */
final class LinkMarkup
{
    /** Keys of the removed template settings, dropped by migrate(). */
    private const LEGACY = ['link_before', 'link_after', 'term_before', 'term_after', 'link_temp_alt', 'term_temp_alt'];

    /**
     * @param string              $url     final href (relative-link format already applied by the caller)
     * @param string              $title   plain (unescaped) title
     * @param array<string,mixed> $options cherrylink_options options
     * @return array{open:string,close:string}
     */
    public static function build(string $url, string $title, array $options): array
    {
        $open = '<a href="' . \esc_url($url) . '"';
        if (($options['link_title_attr'] ?? 'true') !== 'false' && $title !== '') {
            $open .= ' title="' . \esc_attr($title) . '"';
        }
        if (($options['link_new_tab'] ?? 'false') === 'true') {
            $open .= ' target="_blank" rel="noopener"';
        }
        $class = \trim((string) ($options['link_class'] ?? ''));
        if ($class !== '') {
            $open .= ' class="' . \esc_attr($class) . '"';
        }

        return ['open' => $open . '>', 'close' => '</a>'];
    }

    /**
     * Convert the old base64(urlencode(html)) link templates into the three new settings
     * and drop every removed key. Explicit new settings are never overwritten.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function migrate(array $options): array
    {
        $tpl = self::decode($options['link_before'] ?? '') ?: self::decode($options['term_before'] ?? '');
        if ($tpl !== '') {
            if (! isset($options['link_title_attr'])) {
                $options['link_title_attr'] = \strpos($tpl, 'title=') !== false ? 'true' : 'false';
            }
            if (! isset($options['link_new_tab'])) {
                $options['link_new_tab'] = \strpos($tpl, 'target="_blank"') !== false ? 'true' : 'false';
            }
            if (! isset($options['link_class'])) {
                $tokens = [];
                if (\preg_match('/class="([^"]*)"/', $tpl, $m)) {
                    foreach ((array) \preg_split('/\s+/', \trim($m[1])) as $token) {
                        $token = \sanitize_html_class((string) $token);
                        if ($token !== '') {
                            $tokens[] = $token;
                        }
                    }
                }
                $options['link_class'] = \implode(' ', $tokens);
            }
        }
        foreach (self::LEGACY as $key) {
            unset($options[$key]);
        }

        return $options;
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
}
