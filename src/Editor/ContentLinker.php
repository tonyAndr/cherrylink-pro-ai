<?php

declare(strict_types=1);

namespace CherryLink\Editor;

/**
 * Pure HTML helpers for the unified panel: insert a link around a phrase in stored
 * post content (donor insert) and detect whether content already links to a URL.
 *
 * Block numbering mirrors the API's anchor extractor (api/anchors.py): blocks are the
 * non-overlapping matches of `<(p|h[1-6]|li|blockquote|td)(\s[^>]*)?>(.+?)</\1>` whose
 * text (tags stripped, entities decoded, whitespace collapsed) is longer than 10
 * characters. Plain text without any tag is treated like ClassicPanel::buildSourceHtml()
 * does: blank-line separated paragraphs are the blocks.
 *
 * Only text nodes are ever touched: never inside <a>, <script>, <style>, <textarea>,
 * <button>, HTML comments (Gutenberg block delimiters) or tags/attributes.
 */
final class ContentLinker
{
    private const BLOCK_RE = '~<(p|h[1-6]|li|blockquote|td)(\s[^>]*)?>(.+?)</\1>~is';

    // Comment or tag (attribute values may contain '>' when quoted).
    private const TOKEN_RE = '~<!--.*?-->|</?[a-zA-Z][^\s/>]*(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>~s';

    private const MIN_BLOCK_CHARS = 10; // text must be longer than this

    private const SKIP_TAGS = ['a', 'script', 'style', 'textarea', 'button'];

    /**
     * Wrap the first eligible occurrence of $phrase with $open / $close.
     *
     * Search order: block number $blockIndex first (when >= 0 and it exists), then the
     * whole document in order. The matched text keeps its original case and entities.
     *
     * @return string|null New content, or null when the phrase cannot be linked.
     */
    public static function link(string $content, string $phrase, int $blockIndex, string $open, string $close): ?string
    {
        $regex = self::phraseRegex($phrase);
        if ($regex === null || $content === '') {
            return null;
        }

        [$nodes, $blocks] = self::scan($content);

        $ordered = [];
        if ($blockIndex >= 0 && isset($blocks[$blockIndex])) {
            [$bs, $be] = $blocks[$blockIndex];
            foreach ($nodes as $node) {
                if ($node[0] >= $bs && $node[0] + $node[1] <= $be) {
                    $ordered[] = $node;
                }
            }
        }
        foreach ($nodes as $node) {
            $ordered[] = $node;
        }

        $tried = [];
        foreach ($ordered as $node) {
            if (isset($tried[$node[0]])) {
                continue;
            }
            $tried[$node[0]] = true;
            $range = self::matchInNode(\substr($content, $node[0], $node[1]), $regex);
            if ($range === null) {
                continue;
            }
            $abs_start = $node[0] + $range[0];
            $abs_end   = $node[0] + $range[1];
            return \substr($content, 0, $abs_start)
                . $open . \substr($content, $abs_start, $abs_end - $abs_start) . $close
                . \substr($content, $abs_end);
        }

        return null;
    }

    /** Does any <a href> in $content point at $url (absolute, protocol-relative or relative)? */
    public static function linksTo(string $content, string $url): bool
    {
        $target = self::normalizeUrl($url);
        if ($target === null || ($target[0] === '' && $target[1] === '')) {
            return false;
        }
        if (! \preg_match_all('~<a\s[^>]*?href\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i', $content, $m, PREG_SET_ORDER)) {
            return false;
        }
        foreach ($m as $set) {
            $href = $set[1] !== '' ? $set[1] : ($set[2] ?? '');
            $norm = self::normalizeUrl($href);
            if ($norm === null || ($norm[0] === '' && $norm[1] === '')) {
                continue;
            }
            if ($norm[1] === $target[1] && ($norm[0] === '' || $target[0] === '' || $norm[0] === $target[0])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Block texts (index => plain text) exactly as the API numbers them. Exposed for tests
     * and for callers that need to resolve a phrase to a block.
     *
     * @return string[]
     */
    public static function blockTexts(string $content): array
    {
        $out = [];
        foreach (self::findBlocks($content) as $block) {
            $out[] = $block[2];
        }
        return $out;
    }

    // ── Internals ───────────────────────────────────────────────────────────────

    /** @return string|null PCRE or null for an empty phrase */
    private static function phraseRegex(string $phrase): ?string
    {
        $collapsed = \preg_replace('/\s+/u', ' ', $phrase);
        $collapsed = \trim((string) $collapsed);
        if ($collapsed === '') {
            return null;
        }
        $words = \explode(' ', $collapsed);
        $words = \array_map(static function ($w) {
            return \preg_quote($w, '~');
        }, $words);
        return '~(?<![\p{L}\p{N}])' . \implode('[\s\x{00A0}]+', $words) . '(?![\p{L}\p{N}])~iu';
    }

    /**
     * @return array{0:array<int,array{0:int,1:int}>,1:array<int,array{0:int,1:int}>}
     *         [eligible text nodes as [offset,length], blocks as [start,end]]
     */
    private static function scan(string $content): array
    {
        $plain = \strip_tags($content) === $content; // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- exact tag-presence test; wp_strip_all_tags() also trims/strips script text
        if ($plain) {
            return self::scanPlain($content);
        }

        $nodes  = [];
        $pos    = 0;
        $depth  = \array_fill_keys(self::SKIP_TAGS, 0);
        $tokens = [];
        \preg_match_all(self::TOKEN_RE, $content, $tokens, PREG_OFFSET_CAPTURE);

        $eligible = static function () use (&$depth): bool {
            return \array_sum($depth) === 0;
        };

        foreach ($tokens[0] as [$token, $offset]) {
            if ($offset > $pos && $eligible()) {
                $nodes[] = [$pos, $offset - $pos];
            }
            $pos = $offset + \strlen($token);

            if (\substr($token, 0, 4) === '<!--' || ! \preg_match('~^<(/?)([a-zA-Z][^\s/>]*)~', $token, $t)) {
                continue;
            }
            $name = \strtolower($t[2]);
            if (! isset($depth[$name])) {
                continue;
            }
            if ($t[1] === '/') {
                $depth[$name] = \max(0, $depth[$name] - 1);
            } elseif (\substr($token, -2) !== '/>') {
                $depth[$name]++;
            }
        }
        if ($pos < \strlen($content) && $eligible()) {
            $nodes[] = [$pos, \strlen($content) - $pos];
        }

        $blocks = [];
        foreach (self::findBlocks($content) as $block) {
            $blocks[] = [$block[0], $block[1]];
        }

        return [$nodes, $blocks];
    }

    /** @return array{0:array<int,array{0:int,1:int}>,1:array<int,array{0:int,1:int}>} */
    private static function scanPlain(string $content): array
    {
        $nodes  = [];
        $blocks = [];
        $parts  = \preg_split('/\n{2,}/', $content, -1, PREG_SPLIT_OFFSET_CAPTURE);
        foreach ((array) $parts as [$text, $offset]) {
            if (\trim($text) === '') {
                continue;
            }
            $nodes[] = [$offset, \strlen($text)];
            if (\mb_strlen(self::normalizeText($text)) > self::MIN_BLOCK_CHARS) {
                $blocks[] = [$offset, $offset + \strlen($text)];
            }
        }
        return [$nodes, $blocks];
    }

    /** @return array<int,array{0:int,1:int,2:string}> [start, end, text] */
    private static function findBlocks(string $content): array
    {
        if (\strip_tags($content) === $content) { // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- exact tag-presence test
            [, $blocks] = self::scanPlain($content);
            $out = [];
            foreach ($blocks as [$s, $e]) {
                $out[] = [$s, $e, self::normalizeText(\substr($content, $s, $e - $s))];
            }
            return $out;
        }

        $out = [];
        if (\preg_match_all(self::BLOCK_RE, $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $set) {
                $text = self::normalizeText((string) \preg_replace('~<[^>]+>~', ' ', $set[3][0]));
                if (\mb_strlen($text) > self::MIN_BLOCK_CHARS) {
                    $out[] = [$set[0][1], $set[0][1] + \strlen($set[0][0]), $text];
                }
            }
        }
        return $out;
    }

    private static function normalizeText(string $html): string
    {
        $text = \html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $norm = \preg_replace('/\s+/u', ' ', $text);
        return \trim($norm === null ? $text : $norm);
    }

    /**
     * Find the first phrase match inside one text node (raw, entities intact).
     *
     * @return array{0:int,1:int}|null Byte range inside the raw node text.
     */
    private static function matchInNode(string $raw, string $regex): ?array
    {
        $segments = [];
        $decoded  = '';
        $rawPos   = 0;
        $parts    = \preg_split('/(&(?:#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});)/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return null;
        }
        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            $d      = $part;
            $entity = false;
            if ($i % 2 === 1) {
                $d      = \html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $entity = $d !== $part;
                if (! $entity) {
                    $d = $part;
                }
            }
            $segments[] = [\strlen($decoded), \strlen($d), $rawPos, \strlen($part), ! $entity];
            $decoded   .= $d;
            $rawPos    += \strlen($part);
        }

        if (@\preg_match_all($regex, $decoded, $m, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }
        foreach ($m[0] as [$text, $offset]) {
            $start = self::toRaw($segments, $offset, false);
            $end   = self::toRaw($segments, $offset + \strlen($text), true);
            if ($start !== null && $end !== null && $end > $start) {
                return [$start, $end];
            }
        }
        return null;
    }

    /**
     * Map a decoded-text byte offset back to the raw node text.
     *
     * @param array<int,array{0:int,1:int,2:int,3:int,4:bool}> $segments [dStart,dLen,rStart,rLen,plain]
     */
    private static function toRaw(array $segments, int $d, bool $is_end): ?int
    {
        foreach ($segments as [$ds, $dl, $rs, $rl, $plain]) {
            $inside = $is_end ? ($d > $ds && $d <= $ds + $dl) : ($d >= $ds && $d < $ds + $dl);
            if (! $inside) {
                continue;
            }
            if ($plain) {
                return $rs + ($d - $ds);
            }
            if (! $is_end && $d === $ds) {
                return $rs;
            }
            if ($is_end && $d === $ds + $dl) {
                return $rs + $rl;
            }
            return null; // inside a multi-character entity
        }
        return null;
    }

    /** @return array{0:string,1:string}|null [lowercase host, path+query without trailing slash] */
    private static function normalizeUrl(string $url): ?array
    {
        $url = \trim(\html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return null;
        }
        $p = \wp_parse_url($url);
        if (! \is_array($p)) {
            return null;
        }
        $host = \strtolower((string) ($p['host'] ?? ''));
        $path = \rtrim(\rawurldecode((string) ($p['path'] ?? '')), '/');
        $key  = $path . (isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '');
        return [$host, $key];
    }
}
