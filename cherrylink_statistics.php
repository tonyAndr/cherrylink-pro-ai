<?php
/*
 * CherryLink Plugin
 */

// Disable direct access
defined('ABSPATH') || exit;
define('LINKATE_STATISTICS_LIBRARY', true);

// ========================================================================================= //
// ============================== CherryLink Editor Stats  ============================== //
// ========================================================================================= //

// In-Editor incoming links statistics
add_action('wp_ajax_linkate_generate_json', 'linkate_generate_json');
function linkate_generate_json()
{
    $gutenberg_data = json_decode(file_get_contents('php://input'), true);
    $this_id   = isset($gutenberg_data['this_id'])   ? $gutenberg_data['this_id']   : $_POST['this_id'];
    $this_type = isset($gutenberg_data['this_type']) ? $gutenberg_data['this_type'] : $_POST['this_type'];

    $target_url = $this_type === 'post' ? get_permalink((int)$this_id) : get_term_link((int)$this_id);

    if (is_wp_error($target_url) || empty($target_url)) {
        echo json_encode(['links' => '', 'count' => 0]);
        wp_die();
    }

    $api      = new CherryVectorAPI();
    $response = $api->get_incoming_links($target_url);

    if (!$response || empty($response['links'])) {
        echo json_encode(['links' => '', 'count' => 0]);
        wp_die();
    }

    $output = [];
    foreach ($response['links'] as $link) {
        $output[] = [
            'source_id'  => $link['source_id'],
            'source_url' => $link['source_url'],
            'ankor'      => $link['anchor_text'],
        ];
    }

    echo json_encode(['links' => $output, 'count' => $response['count']]);
    wp_die();
}

// Count of posts for export batch sizing
add_action('wp_ajax_linkate_get_all_posts_count', 'linkate_get_all_posts_count');
function linkate_get_all_posts_count()
{
    global $wpdb, $table_prefix;
    $types = array_map(function ($el) {
        return "'" . esc_sql($el) . "'";
    }, $_POST['export_types']);
    $types = implode(',', $types);
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}posts WHERE post_type IN ($types)");
    echo (int)$count;
    linkate_stats_remove_old(false);
    wp_die();
}

// ========================================================================================= //
// ============================== Forward Stats (Workhorse)  ============================== //
// ========================================================================================= //

add_action('wp_ajax_linkate_generate_csv_or_json_prettyfied', 'linkate_generate_csv_or_json_prettyfied');
function linkate_generate_csv_or_json_prettyfied(bool $is_custom_column = false, int $custom_id = 0)
{
    $gutenberg_data = json_decode(file_get_contents('php://input'), true);

    $from_editor       = false;
    $admin_preview     = isset($_POST['admin_preview_stats']);
    $offset            = (int)($_POST['stats_offset'] ?? 0);
    $limit             = (int)($_POST['stats_limit']  ?? 200);
    $include_types     = $_POST['export_types'] ?? [];

    if (isset($_POST['post_ids']) || isset($gutenberg_data['post_ids'])) {
        $from_editor = true;
    }

    $api      = new CherryVectorAPI();
    $response = $api->get_links_stats('forward', $offset, $limit);

    if ($response === null) {
        if (!$is_custom_column) {
            echo json_encode(['status' => 'ERROR']);
            wp_die();
        }
        return false;
    }

    $results = $response['results'] ?? [];

    // Filter to requested post IDs when called from editor
    if ($from_editor) {
        $post_ids_raw = $_POST['post_ids'] ?? $gutenberg_data['post_ids'] ?? '';
        $requested_ids = array_map('intval', explode(',', $post_ids_raw));
        $results = array_filter($results, fn($r) => in_array($r['source_id'], $requested_ids));
    } elseif ($is_custom_column) {
        $results = array_filter($results, fn($r) => $r['source_id'] === $custom_id);
    }

    // FOR POSTS LIST STATS COLUMNS
    if ($is_custom_column) {
        $row = reset($results);
        return $row ? (int)($row['count_sources'] ?? 0) : false;
    }

    $output_array = linkate_results_to_array_forward($results, $from_editor, $include_types);

    if ($from_editor) {
        wp_send_json($output_array);
    } elseif ($admin_preview) {
        echo json_encode(linkate_admin_preview_stats($output_array));
    } else {
        linkate_query_to_csv($output_array, 'cherrylink_stats_' . $offset . '.csv');
        echo json_encode([
            'status' => 'OK',
            'url'    => CHERRYLINK_DIR_URL . 'stats/cherrylink_stats_' . $offset . '.csv',
        ]);
    }

    wp_die();
}

function linkate_results_to_array_forward(array $results, bool $from_editor, array $include_types): array
{
    $output = [];

    foreach ($results as $row) {
        $source_id  = $row['source_id'];
        $source_url = $row['source_url'];
        $post_type  = get_post_type($source_id);

        if (!$from_editor && !empty($include_types) && !in_array($post_type, $include_types)) {
            continue;
        }

        if ($from_editor) {
            $output['"id_' . $source_id . '"'] = [
                $row['count_targets'],
                $row['count_sources'],
            ];
            continue;
        }

        $categories = [];
        $terms = get_the_terms($source_id, 'category');
        if (!empty($terms) && !is_wp_error($terms)) {
            $categories = wp_list_pluck($terms, 'name');
        }
        $cats_str = implode(', ', $categories);

        $links = $row['links'];
        if (empty($links)) {
            // Post with no outgoing links — one row with blanks
            $output[] = [
                $source_id, $post_type, $cats_str, $source_url,
                '', '', $row['count_targets'], $row['count_sources'], 0,
            ];
            continue;
        }

        foreach ($links as $i => $link) {
            $is_broken = $link['link_type'] === 255 ? 1 : 0;
            if ($i === 0 || isset($_POST['duplicate_fields'])) {
                $output[] = [
                    $source_id, $post_type, $cats_str, $source_url,
                    $link['target_url'], $link['anchor_text'],
                    $row['count_targets'], $row['count_sources'], $is_broken,
                ];
            } else {
                $output[] = [
                    '', '', '', '',
                    $link['target_url'], $link['anchor_text'],
                    '', '', $is_broken,
                ];
            }
        }
    }

    return $output;
}

// ========================================================================================= //
// ============================== Backward Stats  ============================== //
// ========================================================================================= //

add_action('wp_ajax_linkate_generate_csv_or_json_prettyfied_backwards', 'linkate_generate_csv_or_json_prettyfied_backwards');
function linkate_generate_csv_or_json_prettyfied_backwards()
{
    $offset        = (int)($_POST['stats_offset'] ?? 0);
    $limit         = (int)($_POST['stats_limit']  ?? 200);
    $include_types = $_POST['export_types'] ?? [];

    $api      = new CherryVectorAPI();
    $response = $api->get_links_stats('backward', $offset, $limit);

    if ($response === null) {
        echo json_encode(['status' => 'ERROR']);
        wp_die();
    }

    $results      = $response['results'] ?? [];
    $output_array = linkate_results_to_array_backward($results, $include_types);

    linkate_query_to_csv($output_array, 'cherrylink_stats_' . $offset . '.csv');
    echo json_encode([
        'status' => 'OK',
        'url'    => CHERRYLINK_DIR_URL . 'stats/cherrylink_stats_' . $offset . '.csv',
    ]);
    wp_die();
}

function linkate_results_to_array_backward(array $results, array $include_types): array
{
    $output = [];

    foreach ($results as $row) {
        $target_url = $row['target_url'];

        // Resolve target post for type/category filtering
        $target_id   = url_to_postid($target_url);
        $post_type   = $target_id ? get_post_type($target_id) : '';
        $categories  = [];
        if ($target_id) {
            $terms = get_the_terms($target_id, 'category');
            if (!empty($terms) && !is_wp_error($terms)) {
                $categories = wp_list_pluck($terms, 'name');
            }
        }
        $cats_str = implode(', ', $categories);

        if (!empty($include_types) && !empty($post_type) && !in_array($post_type, $include_types)) {
            continue;
        }

        $sources = $row['sources'];
        if (empty($sources)) {
            $output[] = [
                $target_id, $post_type, $cats_str, $target_url,
                '', '', $row['count_targets'], $row['count_sources'],
            ];
            continue;
        }

        foreach ($sources as $i => $source) {
            if ($i === 0 || isset($_POST['duplicate_fields'])) {
                $output[] = [
                    $target_id, $post_type, $cats_str, $target_url,
                    $source['source_url'], $source['anchor_text'],
                    $row['count_targets'], $row['count_sources'],
                ];
            } else {
                $output[] = [
                    '', '', '', '',
                    $source['source_url'], $source['anchor_text'],
                    '', '',
                ];
            }
        }
    }

    return $output;
}

// ========================================================================================= //
// ============================== Problems Analysis  ============================== //
// ========================================================================================= //

/**
 * Transform the API /v1/links/problems response into the shape the admin UI expects.
 * Keyed by source_url, same structure as the old linkate_admin_preview_stats().
 */
function linkate_admin_preview_stats(array $ignored = []): array
{
    $api      = new CherryVectorAPI();
    $response = $api->get_link_problems();

    if (!$response || empty($response['problems'])) {
        return [];
    }

    $result = [];
    foreach ($response['problems'] as $p) {
        $url = $p['source_url'];

        $targets = [];
        foreach ($p['repeated_targets'] ?? [] as $rt) {
            $targets[$rt['target_url']] = $rt['count'];
        }

        $err_404 = [];
        foreach ($p['broken_links'] ?? [] as $bl) {
            $err_404[$bl['target_url']] = $bl['anchor'];
        }

        $result[$url] = [
            'url'          => $url,
            'targets'      => $targets,
            'recursion'    => $p['recursive_anchors'] ?? [],
            'err_404'      => $err_404,
            'has_outgoing' => !$p['no_outgoing'],
            'has_incoming' => !$p['no_incoming'],
            'has_repeats'  => $p['has_repeats'],
        ];
    }

    return $result;
}

// ========================================================================================= //
// ============================== CSV Generation ============================== //
// ========================================================================================= //

function linkate_encode_csv(&$value, $key)
{
    if ($value instanceof WP_Error)
        $value = 'NULL_ERROR';
    else
        $value = iconv('UTF-8', 'Windows-1251', (string)$value);
}

function linkate_fputcsv_eol($handle, $array, $delimiter = ',', $enclosure = '"', $eol = "\n")
{
    $return = fputcsv($handle, $array, $delimiter, $enclosure);
    if ($return !== false && "\n" != $eol && 0 === fseek($handle, -1, SEEK_CUR)) {
        fwrite($handle, $eol);
    }
    return $return;
}

function linkate_query_to_csv(array $array, string $filename): void
{
    $direction = $_POST['links_direction'] ?? 'outgoing';
    $headers = [
        'ID',
        'Тип',
        $direction === 'outgoing' ? 'Рубрики_источника' : 'Рубрики_цели',
        $direction === 'outgoing' ? 'URL_источника'     : 'URL_цели',
        $direction === 'outgoing' ? 'URL_цели'          : 'URL_источника',
        'Анкор',
        'Исходящих_ссылок',
        'Входящих_ссылок',
    ];

    if (!file_exists(CHERRYLINK_DIR . 'stats')) {
        mkdir(CHERRYLINK_DIR . 'stats', 0755, true);
    }

    $fp = fopen(CHERRYLINK_DIR . 'stats/' . $filename, 'w');
    array_walk($headers, 'linkate_encode_csv');
    linkate_fputcsv_eol($fp, $headers, ',', '"', "\r\n");

    foreach ($array as $row) {
        array_walk($row, 'linkate_encode_csv');
        linkate_fputcsv_eol($fp, $row, ',', '"', "\r\n");
    }

    fclose($fp);
}

add_action('wp_ajax_linkate_merge_csv_files', 'linkate_merge_csv_files');
function linkate_merge_csv_files()
{
    $directory     = CHERRYLINK_DIR . 'stats/*';
    $masterCSVFile = fopen(CHERRYLINK_DIR . 'stats/cherrylink_stats.csv', 'w+');
    $first_file    = true;

    foreach (glob($directory) as $file) {
        if (strpos($file, 'cherrylink_stats_') === false) continue;

        $data   = [];
        $handle = fopen($file, 'r');
        while (($row = fgetcsv($handle, 1000)) !== false) {
            $data[] = $row;
        }
        fclose($handle);

        if (!$first_file) unset($data[0]); // skip header on subsequent files
        foreach ($data as $value) {
            try {
                linkate_fputcsv_eol($masterCSVFile, $value, ',', '"', "\r\n");
            } catch (Exception $e) {
                // skip malformed rows
            }
        }
        $first_file = false;
    }

    fclose($masterCSVFile);
    linkate_stats_remove_old(true);

    echo json_encode([
        'status' => 'OK',
        'url'    => CHERRYLINK_DIR_URL . 'stats/cherrylink_stats.csv',
    ]);
    wp_die();
}

function linkate_stats_remove_old(bool $only_temp = false): void
{
    if (!file_exists(CHERRYLINK_DIR . 'stats')) return;

    foreach (glob(CHERRYLINK_DIR . 'stats/*') as $file) {
        if (!is_file($file)) continue;
        if ($only_temp && strpos($file, 'cherrylink_stats_') !== false) {
            unlink($file);
        } elseif (!$only_temp) {
            unlink($file);
        }
    }
}
