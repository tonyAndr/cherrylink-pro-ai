<?php

declare(strict_types=1);

namespace CherryLink\Editor;

use CherryLink\Admin\AdminApi;
use CherryLink\Ajax\Security;
use CherryLink\Indexing\IndexHelpers;
use CherryLink\Indexing\IndexHooks;
use CherryLink\Support\Capabilities;
use CherryLink\Support\Connect;
use CherryLink\Support\Options;
use CherryLink\Support\Urls;

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
            'connectUrl' => Connect::settingsUrl(),
            'upgradeUrl' => Urls::UPGRADE,
            'plan'      => $plan = self::plan($options),
            'postLearned' => $is_term ? null : self::postLearned($object_id, $plan),
            'indexExpired' => (new Capabilities())->indexExpired(),
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

    /** Transient caching the eligible-post count (one COUNT query per window). */
    public const TOTAL_TRANSIENT = 'cherrylink_eligible_total';

    /**
     * Plan state for the strip: {name, post_limit, indexed, total}, or null for keys
     * without a plan (legacy). `indexed` comes from the cached capabilities snapshot,
     * then the cached readiness count; no API call.
     *
     * @return array{name:string,post_limit:int|null,indexed:int|null,total:int}|null
     */
    public static function plan(Options $options): ?array
    {
        $meta = $options->meta();
        if (empty($meta['key_valid']) || empty($meta['plan'])) {
            return null;
        }
        $snapshot = (new Capabilities())->snapshot();
        $indexed  = $snapshot['plan']['indexed'] ?? null;
        if ($indexed === null) {
            $indexed = IndexHooks::statusData(false)['index_rows'] ?? null;
        }
        $total = \get_transient(self::TOTAL_TRANSIENT);
        if ($total === false) {
            $total = (int) (new IndexHelpers())->get_indexable_posts_count();
            \set_transient(self::TOTAL_TRANSIENT, $total, 600);
        }

        return [
            'name'       => (string) $meta['plan'],
            'post_limit' => isset($meta['post_limit']) && $meta['post_limit'] !== null ? (int) $meta['post_limit'] : null,
            'indexed'    => $indexed === null ? null : (int) $indexed,
            'total'      => (int) $total,
        ];
    }

    /**
     * Whether this post is inside the plan's learned set (newest `post_limit` by modified),
     * so other posts can be suggested links to it. Null when the plan does not cut the index.
     *
     * @param array{name:string,post_limit:int|null,indexed:int|null,total:int}|null $plan
     */
    public static function postLearned(int $post_id, ?array $plan): ?bool
    {
        if ($plan === null || $plan['post_limit'] === null || $plan['total'] <= $plan['post_limit']) {
            return null;
        }
        $cutoff = AdminApi::learnedCutoff($plan['post_limit']);
        $post   = \get_post($post_id);
        if ($cutoff === null || ! $post) {
            return null;
        }

        return \strcmp((string) $post->post_modified_gmt, $cutoff) >= 0;
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
