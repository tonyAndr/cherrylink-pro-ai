<?php

declare(strict_types=1);

namespace CherryLink\Editor;

/**
 * Builds the {tag} => value map for one search result — the data the two link-card
 * templates (templates/link-card/*.php) are filled with via strtr(). Ported verbatim
 * from the legacy cherrylink_output_tags.php (now a shim that keeps the global
 * linkate_otf_* getters + linkate_link_fields() as delegators, since the render
 * golden-master test dispatches through them).
 *
 * @phpstan-type Result \stdClass
 */
final class LinkFields
{
    /**
     * Build the {tag} => value map for one result.
     *
     * @param \stdClass $result
     * @return array<string,mixed>
     */
    public static function forResult(string $option_key, $result): array
    {
        return [
            '{url}'           => self::url($option_key, $result),
            '{title}'         => self::title($option_key, $result),
            '{categorynames}' => self::categoryNames($option_key, $result),
            '{date}'          => self::date($option_key, $result),
            '{author}'        => self::author($option_key, $result),
            '{postid}'        => self::postId($option_key, $result),
            '{imagesrc}'      => self::imageSrc($option_key, $result),
            '{anons}'         => self::anons($option_key, $result),
            '{suggestions}'   => self::suggestions($option_key, $result),
        ];
    }

    /** @param \stdClass $result */
    public static function postId(string $option_key, $result)
    {
        return $result->ID;
    }

    /** @param \stdClass $result */
    public static function title(string $option_key, $result): string
    {
        if (isset($result->manual_title)) {
            return $result->manual_title; // return manual title for block links
        }

        $value = \htmlspecialchars($result->post_title, ENT_QUOTES, 'UTF-8'); // for json
        $value = \apply_filters('the_title', $value, $result->ID);
        if (\defined('QTRANSLATE_FILE')) {
            $value = \apply_filters('translate_text', $value);
        }
        return $value;
    }

    /** @param \stdClass $result */
    public static function url(string $option_key, $result): string
    {
        $options    = \get_option('linkate-posts', []);
        $url_option = $options['relative_links'];
        $value      = \get_permalink($result->ID);
        return self::unparseUrl($value, $url_option);
    }

    /** @param \stdClass $result */
    public static function author(string $option_key, $result): string
    {
        return \get_the_author_meta('display_name', $result->post_author);
    }

    /** @param \stdClass $result */
    public static function date(string $option_key, $result): string
    {
        return self::formatDate($result->post_date, false, $result->ID);
    }

    /** @param \stdClass $result */
    public static function anons(string $option_key, $result): string
    {
        $options = \get_option($option_key);

        if ($options['anons_len']) {
            $limit = \intval($options['anons_len']);
        } else {
            $limit = 220;
        }
        $meta = \get_post_meta($result->ID, 'perelink', true);
        if ($meta) {
            $value = $meta;
        } else {
            $value = \trim($result->post_excerpt);
            if ($value === '') {
                $value = $result->post_content;
            }
        }
        if (\defined('QTRANSLATE_FILE')) {
            $value = \apply_filters('translate_text', $value);
        }
        $excerpt        = \preg_replace(' (\[.*?\])', '', $value);
        $excerpt        = \strip_shortcodes($excerpt);
        $excerpt        = \strip_tags($excerpt);
        $excerpt        = \mb_substr($excerpt, 0, $limit);
        $next_space_pos = \mb_strripos($excerpt, ' ');
        if ($next_space_pos) {
            $excerpt = \mb_substr($excerpt, 0, $next_space_pos);
        }
        $excerpt = \trim(\preg_replace('/\s+/', ' ', $excerpt));
        $excerpt = \htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8'); // for json
        $excerpt = $excerpt . '...';
        return $excerpt;
    }

    /** @param \stdClass $result */
    public static function suggestions(string $option_key, $result): string
    {
        return \trim($result->suggestions);
    }

    /** @param \stdClass $result */
    public static function categoryNames(string $option_key, $result): string
    {
        $cats  = \get_the_category($result->ID);
        $value = '';
        foreach ($cats as $k => $cat) {
            $value .= $k === 0 ? $cat->name : ', ' . $cat->name;
        }
        return $value;
    }

    /** @param \stdClass $result */
    public static function imageSrc(string $option_key, $result): string
    {
        $options              = \get_option($option_key);
        $url_option           = $options['relative_links'];
        $template_image_size  = isset($options['template_image_size']) ? $options['template_image_size'] : '';
        $crb_placeholder_path = CHERRYLINK_DIR_URL . 'img/imgsrc_placeholder.jpg';

        $size_to_use = $template_image_size;

        // Check Featured Image first
        $imgsrc = \get_the_post_thumbnail_url($result->ID, $size_to_use ? $size_to_use : '');

        if ($imgsrc) {
            $imgsrc = self::unparseUrl($imgsrc, $url_option);
            return $imgsrc;
        }

        // DANGEROUS but possibly can find more images
        $content = $result->post_content;

        // Try to extract img tags from html
        $pattern = '/<img.+?src\s*=\s*[\'|\"](.*?)[\'|\"].+?>/i';
        $found   = \preg_match_all($pattern, $content, $matches);
        if ($found) {
            $imgsrc = $matches[1][0];
            $imgsrc = self::unparseUrl($imgsrc, $url_option);
        }

        // Well, shite, return placeholder
        if (! $imgsrc) { // placeholder
            return $crb_placeholder_path;
        }

        // Now we try to find suitable size — first check using vanilla url
        $att_id = \attachment_url_to_postid($imgsrc);

        // cut the shit outta here
        if (! $att_id) {
            $imgsrc = \preg_replace("~-\d{2,4}x\d{2,4}(?!.*-\d{2,4}x\d{2,4})~", '', $imgsrc);
            $att_id = \attachment_url_to_postid($imgsrc);
        }

        // If not found again, return imgsrc from prev step and relax
        if (! $att_id) {
            return $imgsrc;
        }

        // Now lets try to get needed size — if size is empty then original will be returned
        $attachement = \wp_get_attachment_image_url($att_id, $size_to_use ? $size_to_use : '');
        if ($attachement) {
            $imgsrc = $attachement;
        }

        if (! $imgsrc) { // placeholder
            $imgsrc = $crb_placeholder_path;
        }

        return $imgsrc;
    }

    /**
     * @param string    $date
     * @param string|false $fmt
     * @param int|string   $id
     */
    public static function formatDate($date, $fmt, $id): string
    {
        if (! $fmt) {
            $fmt = \get_option('date_format');
        }
        $d = \mysql2date($fmt, $date);
        $d = \apply_filters('get_the_time', $d, $fmt, $id);
        return \apply_filters('the_time', $d, $fmt);
    }

    public static function unparseUrl(string $url, string $opt): string
    {
        $url        = \trim($url);
        $parsed_url = \parse_url($url);
        if ($parsed_url === false) {
            return '';
        }
        $REQUEST_SCHEME = ! empty($_SERVER['REQUEST_SCHEME']) ? \sanitize_text_field(\wp_unslash($_SERVER['REQUEST_SCHEME'])) . '://' : '';
        $HTTP_HOST      = ! empty($_SERVER['HTTP_HOST']) ? \sanitize_text_field(\wp_unslash($_SERVER['HTTP_HOST'])) : '';

        if ($parsed_url) {
            $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : $REQUEST_SCHEME;
            $host   = isset($parsed_url['host']) ? $parsed_url['host'] : $HTTP_HOST;
            $port   = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
            $path   = isset($parsed_url['path']) ? $parsed_url['path'] : '';
            if ($opt === 'full') {
                return "$scheme$host$port$path";
            }
            if ($opt === 'no_proto') {
                return "//$host$port$path";
            }
            if ($opt === 'no_domain') {
                return "$path";
            }
        }
        return $url;
    }
}
