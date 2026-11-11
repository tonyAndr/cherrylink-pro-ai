<?php

declare(strict_types=1);

namespace CherryLink\Editor;

use CherryLink\Ajax\Security;
use CherryLink\Support\Options;

/**
 * The `cherrylinkPanel` bootstrap object shared by the Gutenberg and Classic hosts.
 * Emitted with wp_add_inline_script (not wp_localize_script) so booleans and integers
 * keep their JSON types.
 */
final class PanelConfig
{
    /**
     * @return array<string,mixed>
     */
    public static function build(Options $options, string $host, int $object_id, bool $is_term = false, string $taxonomy = ''): array
    {
        $main = $options->main();
        $cfg  = [
            'ajaxUrl'   => \admin_url('admin-ajax.php'),
            'nonce'     => Security::nonce(),
            'postId'    => $object_id,
            'isTerm'    => $is_term,
            'host'      => $host,
            'options'   => [
                'showCatFilter'     => ($main['show_cat_filter'] ?? 'false') === 'true' || ($main['show_cat_filter'] ?? false) === true,
                'noSelectionAction' => (string) ($main['no_selection_action'] ?? ''),
                'limit'             => (int) ($main['limit_ajax'] ?? 0),
            ],
            'licensed'  => ! empty($options->meta()['key_valid']),
            'editUrlBase' => \admin_url('post.php?action=edit&post='),
        ];
        if ($is_term && $taxonomy !== '') {
            $cfg['termTaxonomy'] = $taxonomy;
        }
        if ($cfg['options']['showCatFilter']) {
            $cfg['categories'] = self::categories();
        }
        return $cfg;
    }

    /** Inline script defining window.cherrylinkPanel; attach 'before' the bundle. */
    public static function inlineScript(array $config): string
    {
        return 'window.cherrylinkPanel = ' . \wp_json_encode($config) . ';';
    }

    /**
     * Category list in tree order: [{id, name, level}].
     *
     * @return array<int,array{id:int,name:string,level:int}>
     */
    public static function categories(): array
    {
        $terms = \get_terms(['taxonomy' => 'category', 'hide_empty' => false]);
        if (! \is_array($terms)) {
            return [];
        }
        $by_parent = [];
        foreach ($terms as $t) {
            $by_parent[(int) $t->parent][] = $t;
        }
        $out  = [];
        $walk = static function (int $parent, int $level) use (&$walk, &$out, $by_parent): void {
            foreach ($by_parent[$parent] ?? [] as $t) {
                $out[] = ['id' => (int) $t->term_id, 'name' => (string) $t->name, 'level' => $level];
                $walk((int) $t->term_id, $level + 1);
            }
        };
        $walk(0, 0);
        return $out;
    }
}
