<?php
/**
 * Classic (TinyMCE) editor panel.
 *
 * @var array  $options          linkate-posts option array
 * @var string $cl_exists_class  css class for the (commented-out) link-exists toggle
 * @var string $tab_links_url    articles-tab icon URL
 * @var string $tab_taxonomies_url
 * @var string $tab_stats_url
 *
 * Data attributes carry link-template markup consumed by cherry-front.js via
 * getAttribute() — esc_attr() here is transparent to JS (the parser decodes it)
 * and prevents a stray quote from breaking the attribute.
 */

defined('ABSPATH') || exit;
?>
    <div id="linkate-box" class="linkate-custom-box">
        <div class="linkate-close-btn">&#x2716;</div>
        <h2 class="hndle"><span>CherryLink Pro</span></h2>
        <div class="linkate-filter-bar">
            <!-- <div>
                <div>
                    <input id="hide_that_exists" type="checkbox" checked>
                    <label class="<?= $cl_exists_class ?>" for="hide_that_exists">LINK</label>
                </div>
                <div>
                    <input id="show_that_exists" type="checkbox" checked>
                    <label for="show_that_exists" class="linkate-link">LINK</label>
                </div>
            </div> -->
            <div>
                <input id="filter_by_title" type="text" placeholder="<?php esc_attr_e('Фильтр', 'cherrylink'); ?>">
                <span class="filter-clear-box"></span>
            </div>
        </div>
        <div class="linkate-tabs">
            <div class="tab tab-articles linkate-tab-selected" title="<?php esc_attr_e('Записи', 'cherrylink'); ?>"><img src="<?php echo esc_url($tab_links_url); ?>" width="20" height="20" alt="<?php esc_attr_e('Записи', 'cherrylink'); ?>"></div>
            <div class="tab tab-taxonomy" title="<?php esc_attr_e('Таксономии', 'cherrylink'); ?>"><img src="<?php echo esc_url($tab_taxonomies_url); ?>" width="20" height="20" alt="<?php esc_attr_e('Таксономии', 'cherrylink'); ?>"></div>
            <div class="tab tab-stats" title="<?php esc_attr_e('Статистика ссылок', 'cherrylink'); ?>"><img src="<?php echo esc_url($tab_stats_url); ?>" width="20" height="20" alt="<?php esc_attr_e('Статистика', 'cherrylink'); ?>"></div>
        </div>
        <div class="suggestions-panel-back">&#10094; <?php esc_html_e('Назад', 'cherrylink'); ?></div>
        <div class="inside" id="cherrylink_meta_inside">
            <span id="link_template" data-before="<?php echo esc_attr($options['link_before']); ?>" data-after="<?php echo esc_attr($options['link_after']); ?>" data-temp-alt="<?php echo esc_attr($options['link_temp_alt']); ?>" hidden></span>
            <span id="term_template" data-before="<?php echo esc_attr($options['term_before']); ?>" data-after="<?php echo esc_attr($options['term_after']); ?>" data-term-temp-alt="<?php echo esc_attr($options['term_temp_alt']); ?>" hidden></span>
            <?php
            if (isset($options['show_cat_filter']) && ($options['show_cat_filter'] === 'true' || $options['show_cat_filter'] === true)) {
                echo create_quick_cat_select();
            }
            ?>
            <div class="linkate-box-container container-articles">

                <div id="linkate-links-list"></div>
                <div class="linkate-load-more">
                    <div class="lds-ellipsis">
                        <div></div>
                        <div></div>
                        <div></div>
                        <div></div>
                    </div>
                    <div class="load-more-text"><?php esc_html_e('Загрузить еще...', 'cherrylink'); ?></div>
                </div>
            </div>
            <div class="linkate-box-container container-taxonomy">
                <?php
                // suppress useless notices temporarely
                $error_level = error_reporting();
                error_reporting(E_ALL & ~E_NOTICE);
                echo hierarchical_term_tree();
                // revert it back
                error_reporting($error_level);
                ?>
            </div>
            <div class="linkate-box-container container-stats" style="display:none">
                <div id="cl-incoming-list"><div class="cl-incoming-loading"><?php esc_html_e('Загрузка...', 'cherrylink'); ?></div></div>
            </div>
        </div>
        <div class="linkate-total-links">
            <div class="total-links-counter">
                <div> <?php esc_html_e('Исходящих', 'cherrylink'); ?></div>
                <div id="links-count-total">?</div>
                <div class="cl-footer-incoming"> <?php esc_html_e('Входящих', 'cherrylink'); ?></div>
                <div id="links-count-targets" class="cl-footer-incoming">?</div>
            </div>
        </div>
    </div>
<?php
