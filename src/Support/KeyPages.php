<?php

declare(strict_types=1);

namespace CherryLink\Support;

use CherryLink\Api\Client;

/**
 * Key pages (specs/admin-ui.md, api/specs/key-pages.md): the last API result and the
 * site-wide pin/demote choices, both kept in non-autoloaded options. The API is stateless,
 * so every refresh sends the pins and stores the enriched answer.
 */
final class KeyPages
{
    public const PAGES_OPTION = 'cherrylink_key_pages';
    public const PINS_OPTION  = 'cherrylink_key_pins';

    private const MAX_PINS = 500;

    /** Ranking tie-breaker in raw cosine; ~3 points of displayed relevance (0.82..0.95 band). */
    public const BOOST = 0.004;

    /** @var Client|null */
    private $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client;
    }

    /** @return array{pin:int[],demote:int[]} */
    public function pins(): array
    {
        $raw = \get_option(self::PINS_OPTION, []);
        $ids = static function ($list): array {
            return \array_values(\array_unique(\array_filter(\array_map('intval', \is_array($list) ? $list : []), static function (int $id): bool {
                return $id > 0;
            })));
        };

        return ['pin' => $ids($raw['pin'] ?? []), 'demote' => $ids($raw['demote'] ?? [])];
    }

    /** Set one post's choice: pin, demote, or clear (auto). Returns false for an unknown mode. */
    public function setMode(int $post_id, string $mode): bool
    {
        if ($post_id < 1 || ! \in_array($mode, ['pin', 'demote', 'clear'], true)) {
            return false;
        }
        $pins = $this->pins();
        foreach (['pin', 'demote'] as $list) {
            $pins[$list] = \array_values(\array_diff($pins[$list], [$post_id]));
        }
        if ($mode !== 'clear') {
            $pins[$mode][] = $post_id;
        }
        \update_option(self::PINS_OPTION, [
            'pin'    => \array_slice($pins['pin'], -self::MAX_PINS),
            'demote' => \array_slice($pins['demote'], -self::MAX_PINS),
        ], false);

        return true;
    }

    /** Drop the stored result (index purged / data removed); the pins are the user's and stay. */
    public static function clearResult(): void
    {
        \delete_option(self::PAGES_OPTION);
    }

    /**
     * Ask the API with the current pins and store the enriched result.
     *
     * @return array<string,mixed>|null the stored result, null when the API call failed
     */
    public function refresh(): ?array
    {
        $pins     = $this->pins();
        $client   = $this->client ?? new Client(null, Client::CONTEXT_BACKGROUND);
        $response = $client->key_pages($pins['pin'], $pins['demote']);
        if (! \is_array($response)) {
            return null;
        }
        $pages = [];
        foreach ((array) ($response['key_pages'] ?? []) as $page) {
            if (\is_array($page) && (int) ($page['post_id'] ?? 0) > 0) {
                $pages[] = $this->enrich($page);
            }
        }
        $stored = [
            'checked_at'       => \gmdate('c'),
            'key_pages'        => $pages,
            'posts_considered' => (int) ($response['posts_considered'] ?? 0),
        ];
        \update_option(self::PAGES_OPTION, $stored, false);

        return $stored;
    }

    /** @return array{checked_at:?string,key_pages:array<int,array<string,mixed>>,posts_considered:?int} */
    public function stored(): array
    {
        $stored = \get_option(self::PAGES_OPTION, null);
        if (! \is_array($stored) || ! isset($stored['key_pages']) || ! \is_array($stored['key_pages'])) {
            return ['checked_at' => null, 'key_pages' => [], 'posts_considered' => null];
        }

        return [
            'checked_at'       => isset($stored['checked_at']) ? (string) $stored['checked_at'] : null,
            'key_pages'        => $stored['key_pages'],
            'posts_considered' => isset($stored['posts_considered']) ? (int) $stored['posts_considered'] : null,
        ];
    }

    /**
     * Admin payload: the stored result plus the demoted posts (they never appear as key
     * pages, so the UI needs them to offer "Restore").
     *
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        $demoted = [];
        foreach ($this->pins()['demote'] as $id) {
            $title = $this->title($id, '');
            $demoted[] = ['post_id' => $id, 'title' => $title !== '' ? $title : '#' . $id, 'edit_url' => (string) \get_edit_post_link($id, 'raw')];
        }

        return $this->stored() + ['demoted' => $demoted];
    }

    /**
     * What the editor panel needs for one post.
     *
     * @return array{is_key:bool,key:?array<string,mixed>,members_not_linking:int,pinned:bool,demoted:bool}
     */
    public function forPost(int $post_id): array
    {
        $pins = $this->pins();
        $out  = [
            'is_key' => false, 'key' => null, 'members_not_linking' => 0,
            'pinned' => \in_array($post_id, $pins['pin'], true), 'demoted' => \in_array($post_id, $pins['demote'], true),
        ];
        foreach ($this->stored()['key_pages'] as $page) {
            $key = [
                'post_id'  => (int) ($page['post_id'] ?? 0),
                'title'    => (string) ($page['title'] ?? ''),
                'url'      => (string) ($page['url'] ?? ''),
                'view_url' => (string) ($page['view_url'] ?? ''),
            ];
            if ($key['post_id'] === $post_id) {
                $out['is_key'] = true;
                $out['key']    = $key;
                foreach ((array) ($page['members'] ?? []) as $m) {
                    if (empty($m['links_to_key'])) {
                        $out['members_not_linking']++;
                    }
                }
                return $out;
            }
            foreach ((array) ($page['members'] ?? []) as $m) {
                if ((int) ($m['post_id'] ?? 0) === $post_id) {
                    $out['key'] = $key;
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * Ranking tweak for suggestions: key pages sort slightly above equally scored posts.
     * Stable; the caller keeps displaying the raw score.
     *
     * @param array<int,float> $scores post_id => raw score, in the API's ranking order
     * @param int[]            $key_ids
     * @return int[] post ids, best first
     */
    public static function rank(array $scores, array $key_ids): array
    {
        $keys = \array_flip($key_ids);
        $ids  = \array_keys($scores);
        $pos  = \array_flip($ids);
        \usort($ids, static function ($a, $b) use ($scores, $keys, $pos): int {
            $sa = $scores[$a] + (isset($keys[$a]) ? self::BOOST : 0.0);
            $sb = $scores[$b] + (isset($keys[$b]) ? self::BOOST : 0.0);

            return $sa === $sb ? $pos[$a] <=> $pos[$b] : ($sa < $sb ? 1 : -1);
        });

        return \array_map('intval', $ids);
    }

    /** @return int[] ids of the stored key pages */
    public function keyIds(): array
    {
        return \array_values(\array_filter(\array_map(static function ($p): int {
            return (int) ($p['post_id'] ?? 0);
        }, $this->stored()['key_pages'])));
    }

    /**
     * @param array<string,mixed> $page
     * @return array<string,mixed>
     */
    private function enrich(array $page): array
    {
        $id            = (int) $page['post_id'];
        $page['title'] = $this->title($id, (string) ($page['title'] ?? ''));
        $page['edit_url'] = (string) \get_edit_post_link($id, 'raw');
        $link          = \get_permalink($id);
        $page['view_url'] = \is_string($link) && $link !== '' ? $link : (string) ($page['url'] ?? '');
        $members       = [];
        foreach ((array) ($page['members'] ?? []) as $m) {
            if (! \is_array($m) || (int) ($m['post_id'] ?? 0) < 1) {
                continue;
            }
            $mid               = (int) $m['post_id'];
            $m['title']        = $this->title($mid, (string) ($m['title'] ?? ''));
            $m['edit_url']     = (string) \get_edit_post_link($mid, 'raw');
            $members[]         = $m;
        }
        $page['members'] = $members;

        return $page;
    }

    private function title(int $id, string $fallback): string
    {
        $title = \html_entity_decode((string) \get_the_title($id), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $title !== '' ? $title : $fallback;
    }
}
