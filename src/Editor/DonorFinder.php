<?php

declare(strict_types=1);

namespace CherryLink\Editor;

use CherryLink\Api\Client;
use CherryLink\Support\Language;

/**
 * Donor candidates for one target post: published posts semantically close to the
 * target that do not yet link to it, each with the best anchor phrase inside it.
 */
final class DonorFinder
{
    public const MAX_DONORS = 5;

    /** @var Client */
    private $api;

    public function __construct(?Client $api = null)
    {
        $this->api = $api ?? new Client();
    }

    /**
     * @return array<int,array<string,mixed>> Donor rows (see specs/unified-panel.md)
     */
    public function find(int $target_id): array
    {
        $target = \get_post($target_id);
        if (! $target || (string) $target->post_title === '') {
            return [];
        }
        $lang = Language::forPost($target_id);
        // Same query shape as the outgoing list (title + text), so scores are comparable across tabs.
        $query = \trim((string) $target->post_title . "\n" . \wp_strip_all_tags((string) $target->post_content));
        $hits  = $this->api->search($query, '', 12, $lang, 0, $target);
        if (empty($hits)) {
            return [];
        }

        $target_url = (string) \get_permalink($target_id);
        $me         = (int) \get_current_user_id();
        $donors     = [];

        foreach ($hits as $hit) {
            if (\count($donors) >= self::MAX_DONORS) {
                break;
            }
            $id = (int) ($hit['post_id'] ?? 0);
            if ($id < 1 || $id === $target_id) {
                continue;
            }
            $donor = \get_post($id);
            if (! $donor || $donor->post_status !== 'publish') {
                continue;
            }
            $content = (string) $donor->post_content;
            if ($target_url !== '' && ContentLinker::linksTo($content, $target_url)) {
                continue;
            }

            $can_edit  = (bool) \current_user_can('edit_post', $id);
            $lock_user = $can_edit ? (int) \wp_check_post_lock($id) : 0;
            $locked_by = '';
            if ($lock_user > 0 && $lock_user !== $me) {
                $u         = \get_userdata($lock_user);
                $locked_by = $u ? (string) $u->display_name : (string) $lock_user;
            }

            // Anchor lookup is the expensive call: only for donors we could actually edit.
            $suggestion = null;
            if ($can_edit && $locked_by === '') {
                $found = $this->api->suggest_anchors(ClassicPanel::buildSourceHtml($content), (string) $target->post_title, 1, $lang, $target_id);
                if (! empty($found[0]['phrase'])) {
                    $suggestion = [
                        'phrase'      => (string) $found[0]['phrase'],
                        'context'     => (string) ($found[0]['context'] ?? ''),
                        'block_index' => (int) ($found[0]['block_index'] ?? 0),
                    ];
                }
            }

            $row = [
                'donor_id'   => $id,
                'score'      => \round((float) ($hit['score'] ?? 0), 4),
                'title'      => \html_entity_decode((string) \get_the_title($id), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'edit_url'   => \admin_url('post.php?post=' . $id . '&action=edit'),
                'view_url'   => (string) \get_permalink($id),
                'in'         => (int) \get_post_meta($id, 'cherrylink_incoming', true),
                'can_edit'   => $can_edit,
                'suggestion' => $suggestion,
            ];
            if ($locked_by !== '') {
                $row['locked_by'] = $locked_by;
            }
            $donors[] = $row;
        }

        return $donors;
    }
}
