<?php
/*
 * CherryLink Plugin
 */

// Disable direct access
defined('ABSPATH') || exit;

class CL_Index_Helpers
{

    public $stemmer;
    public $options;
    public $wpdb;
    public $table_prefix;
    public $allowed_post_types;

    public function __construct($stemmer, $options, $wpdb)
    {
        $this->options = $options;
        $this->stemmer = $stemmer;
        $this->wpdb = $wpdb;

        $this->table_prefix = $wpdb->prefix;

        // $hide_types = array('attachment', 'wp_block', 'revision', 'nav_menu_item', 'custom_css', 'oembed_cache', 'user_request', 'customize_changeset', 'post_format', 'nav_menu', 'link_category');
        $args = array(
            'public'   => true,
            '_builtin' => false
        );
        $types = get_post_types($args, 'objects');
        $this->allowed_post_types = ['post', 'page'];
        // add custom types
        foreach ($types as $type) {
            // if (!in_array($type->name, $hide_types)) {
                $this->allowed_post_types[] = $type->name;
            // }
        }
    }

    public function prepare_stopwords()
    {
        $words_table = $this->table_prefix . "linkate_stopwords";
        $black_words = $this->wpdb->get_col("SELECT stemm FROM $words_table WHERE is_white = 0 GROUP BY stemm");
        $white_words = $this->wpdb->get_col("SELECT word FROM $words_table WHERE is_white = 1");
        $linkate_overusedwords["black"] = array_flip(array_filter($black_words));
        $linkate_overusedwords["white"] = array_flip(array_filter($white_words));
        return $linkate_overusedwords;
    }

    public function linkate_sp_terms_by_freq($ID, $num_terms = 150, $is_term = 0)
    {
        if (!$ID) return array('', '', '', '');
        $table_name = $this->table_prefix . 'linkate_posts';
        $terms = '';
        $results = $this->wpdb->get_results("SELECT title, content, custom_fields, suggestions FROM $table_name WHERE pID=$ID AND is_term=$is_term LIMIT 1", ARRAY_A);
        if ($results) {
            $word = strtok($results[0]['content'], ' ');
            $n = 0;
            $wordtable = array();
            while ($word !== false) {
                if (!array_key_exists($word, $wordtable)) {
                    $wordtable[$word] = 0;
                }
                $wordtable[$word] += 1;
                $word = strtok(' ');
            }
            arsort($wordtable);
            if ($num_terms < 1) $num_terms = 1;
            $wordtable = array_slice($wordtable, 0, $num_terms);

            foreach ($wordtable as $word => $count) {
                $terms .= ' ' . $word;
            }

            $res[] = $terms;
            $res[] = $results[0]['title'];
            $res[] = $results[0]['custom_fields'];
            $res[] = $results[0]['suggestions'];
            return $res;
        }
        return array('', '', '', '');
    }

    // // Extract the most popular words to make ankor suggestions 
    // public function linkate_sp_terms_by_freq_ankor($content)
    // {
    //     if (empty($content))
    //         return "";
    //     $terms = "";
    //     $num_terms = 3; // max words num
    //     $word = strtok($content, ' ');
    //     $n = 0;
    //     $wordtable = array();
    //     while ($word !== false) {
    //         if (!array_key_exists($word, $wordtable)) {
    //             $wordtable[$word] = 0;
    //         }
    //         $wordtable[$word] += 1;
    //         $word = strtok(' ');
    //     }
    //     arsort($wordtable);
    //     if ($num_terms < 1) $num_terms = 1;
    //     $wordtable = array_slice($wordtable, 0, $num_terms);

    //     foreach ($wordtable as $word => $count) {
    //         $terms .= ' ' . $word;
    //     }
    //     return $terms;
    // }
    public function linkate_sp_prepare_suggestions($title, $content, $custom_fields, $suggestions_donors_src, $suggestions_donors_join)
    {
        if (empty($suggestions_donors_src))
            return '';

        $suggestions_donors_src = explode(',', $suggestions_donors_src);

        // change old settings
        if (!in_array('title', $suggestions_donors_src) && !in_array('content', $suggestions_donors_src)) {
            $suggestions_donors_src = array('title');
        }

        $array = array();
        if (in_array('title', $suggestions_donors_src))
            $array[] = array_filter($title);
        if (in_array('content', $suggestions_donors_src)) {
            // get most used words from content
            $wordlist = array_count_values($content);
            arsort($wordlist);
            $wordlist = array_slice($wordlist, 0, 20);
            $wordlist = array_keys($wordlist);
            $array[] = array_filter($wordlist);
        }
        if (in_array('custom_fields', $suggestions_donors_src)) {
            // get most used words from content
            $wordlist = array_count_values($custom_fields);
            arsort($wordlist);
            $wordlist = array_slice($wordlist, 0, 20);
            $wordlist = array_keys($wordlist);
            $array[] = array_filter($wordlist);
        }
        $array = array_filter($array);
        if (empty($array))
            return '';

        $array = array_values($array);
        if (sizeof($array) === 1) {
            return implode(' ', array_unique($array[0]));
        }

        if ($suggestions_donors_join == 'intersection') {
            $result = array_unique(array_intersect(...$array));
            return  implode(' ', $result);
        } else { //join
            $result = array_unique(array_merge(...$array));
            return  implode(' ', $result);
        }
    }


    public function linkate_sp_mb_clean_words($text)
    {
        mb_regex_encoding('UTF-8');
        mb_internal_encoding('UTF-8');
        $text = strip_tags($text);
        $text = mb_strtolower($text);
        $text = str_replace("’", "'", $text); // convert MSWord apostrophe
        $text = preg_replace(array('/\[(.*?)\]/u', '/&[^\s;]+;/u', '/‘|’|—|“|”|–|…/u', "/'\W/u"), ' ', $text); //anything in [..] or any entities
        return     $text;
    }

    public function linkate_sp_get_post_terms($wordlist, $min_len, $linkate_overusedwords, $clean_suggestions_stoplist)
    {
        mb_regex_encoding('UTF-8');
        mb_internal_encoding('UTF-8');
        $stemms = '';
        $words = array();

        reset($wordlist);

        if ($this->stemmer->Stem_Enabled) {
            foreach ($wordlist as $word) {
                if (mb_strlen($word) > $min_len || array_key_exists($word, $linkate_overusedwords["white"])) {
                    $stemm = $this->stemmer->stem_word($word);
                    if (mb_strlen($stemm) <= 2) continue;
                    if (!array_key_exists($stemm, $linkate_overusedwords["black"]))
                        $stemms .= $stemm . ' ';
                    if ($clean_suggestions_stoplist == 'false' || ($clean_suggestions_stoplist == 'true' && !array_key_exists($stemm, $linkate_overusedwords["black"])))
                        $words[] = $word;
                }
            }
        } else {
            foreach ($wordlist as $word) {
                if (mb_strlen($word) > $min_len) {
                    $words[] = $word;
                }
            }
        }

        unset($wordlist);
        if (empty($stemms) && !$this->stemmer->Stem_Enabled) $stemms = implode(' ', $words);

        // remove spaces
        $words = array_filter($words, function ($el) {
            return !empty(trim($el));
        });
        $stemms = trim($stemms);

        return array($stemms, $words);
    }

    public function linkate_sp_get_title_terms($text, $min_len, $linkate_overusedwords, $clean_suggestions_stoplist)
    {
        mb_regex_encoding('UTF-8');
        mb_internal_encoding('UTF-8');
        $wordlist = mb_split("\W+", $this->linkate_sp_mb_clean_words($text));
        $stemms = '';
        $words = array();
        if ($this->stemmer->Stem_Enabled) {
            foreach ($wordlist as $word) {
                if (mb_strlen($word) > $min_len || array_key_exists($word, $linkate_overusedwords["white"])) {
                    $stemm = $this->stemmer->stem_word($word);
                    if (mb_strlen($stemm) <= 1) continue;
                    if (!array_key_exists($stemm, $linkate_overusedwords["black"]))
                        $stemms .= $stemm . ' ';
                    if ($clean_suggestions_stoplist == 'false' || ($clean_suggestions_stoplist == 'true' && !array_key_exists($stemm, $linkate_overusedwords["black"])))
                        $words[] = $word;
                }
            }
        } else {
            foreach ($wordlist as $word) {
                if (mb_strlen($word) > $min_len)
                    $words[] = $word;
            }
        }

        unset($wordlist);
        if (empty($stemms) && !$this->stemmer->Stem_Enabled)
            $stemms = implode(' ', $words);
        return array($stemms, $words);
    }

    public function linkate_sp_get_tag_terms($ID)
    {
        if (!function_exists('get_object_term_cache')) return '';
        $tags = array();
        $terms = $this->wpdb->terms;
        $term_taxonomy = $this->wpdb->term_taxonomy;
        $term_relationships = $this->wpdb->term_relationships;
        $query = "SELECT t.name FROM $terms AS t INNER JOIN $term_taxonomy AS tt ON tt.term_id = t.term_id INNER JOIN $term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy = 'post_tag' AND tr.object_id = '$ID'";
        $tags = $this->wpdb->get_col($query);
        if (!empty($tags)) {
            mb_internal_encoding('UTF-8');
            foreach ($tags as $tag) {
                $newtags[] = mb_strtolower(str_replace('"', "'", $tag));
            }
            $newtags = str_replace(' ', '_', $newtags);
            $tags = implode(' ', $newtags);
        } else {
            $tags = '';
        }
        return $tags;
    }



    public function linkate_process_batch_overused_words($batch_content_array, $common_words, $min_len, $black_words_common)
    {
        foreach ($batch_content_array as $key => $word) {
            # code...
            if (mb_strlen($word) > intval($min_len) && !array_key_exists($word, $black_words_common)) {
                if (!array_key_exists($word, $common_words)) {
                    $common_words[$word] = 0;
                } else {
                    $common_words[$word] += 1;
                }
            }
        }
        arsort($common_words);
        $common_words = array_slice($common_words, 0, 100);
        return $common_words;
    }

    public function linkate_sp_delete_index_entry($postID)
    {
        $table_name = $this->table_prefix . 'linkate_posts';
        $this->wpdb->query("DELETE FROM $table_name WHERE pID = $postID ");
        return $postID;
    }

    public function get_indexable_posts_count()
    {
        $table = $this->wpdb->posts;
        $allowed_types = $this->get_allowed_types_sql();
        return $this->wpdb->get_var("SELECT COUNT(*) FROM $table WHERE `post_type` in ($allowed_types)");
    }

    public function get_allowed_types_sql() {
        return implode(', ', array_map(function ($type) {
            return "'".$type."'";
        }, $this->allowed_post_types));
    }

    /**
     * Returns the allowed post type list without requiring an instance.
     * Used by the vector API indexer which doesn't need stemmer/options.
     */
    public static function get_allowed_types_list(): array
    {
        $types = get_post_types(['public' => true, '_builtin' => false], 'objects');
        $list  = ['post', 'page'];
        foreach ($types as $type) {
            $list[] = $type->name;
        }
        return $list;
    }

    public function collect_custom_fields($post, $stopwords)
    {
        $custom_fields = (isset($this->options['index_custom_fields']) && !empty($this->options['index_custom_fields'])) ? $this->options['index_custom_fields'] : false;
        if ($custom_fields === false) {
            return ['', []];
        }
        $custom_fields = explode(PHP_EOL, $custom_fields);
        $collected_text = '';

        // could be real post object or limited version, check the date field cuz it exists only in real one
        $real_post_object = isset($post->post_date) ? $post : get_post(intval($post->ID));

        foreach ($custom_fields as $key => $field) {
            $collected_text .= ' ' . @$real_post_object->{$field};
        }

        $tokens = mb_split("\W+", $this->linkate_sp_mb_clean_words($collected_text));
        list($content, $content_sugg) = $this->linkate_sp_get_post_terms($tokens, $this->options['term_length_limit'], $stopwords, $this->options['clean_suggestions_stoplist']);
        $content = iconv("UTF-8", "UTF-8//IGNORE", $content);
        if (!$content) $content = '';

        return [$content, $content_sugg];
    }
}
