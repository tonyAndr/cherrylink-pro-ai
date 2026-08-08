<?php
/**
 * CherryLink settings page shell: header + tabbed subpage host.
 */

defined('ABSPATH') || exit;
?>

    <div class="wrap">
        <div class="cherry-admin-logo">
            <h1></h1>
            <div class="<?= CL_TWC::$H1 ?>">CherryLink Pro 🍒</div>
        </div>

        <?php
        $m = new lp_admin_subpages();
        $m->add_subpage(__('Основные', 'cherrylink'), 'main', 'linkate_posts_main_options_subpage');
        $m->add_subpage(__('Сканирование сайта', 'cherrylink'), 'scan', 'linkate_posts_index_options_subpage');
        $m->add_subpage(__('Для экспертов', 'cherrylink'), 'pro', 'linkate_posts_expert_options_subpage');
        $m->add_subpage(__('Статистика', 'cherrylink'), 'statistics', 'linkate_posts_statistics_options_subpage');
        $m->display();
        // add_action('in_admin_footer', 'linkate_posts_admin_footer');
        ?>
    </div>
<?php
