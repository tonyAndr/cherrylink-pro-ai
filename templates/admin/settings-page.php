<?php
/**
 * CherryLink settings page shell: header + tabbed subpage host.
 *
 * @var \CherryLink\Admin\Subpages $menu  preconfigured subpage router
 */

defined('ABSPATH') || exit;
?>

    <div class="wrap">
        <div class="cherry-admin-logo">
            <h1></h1>
            <?php
            $h1_class = \CherryLink\Support\Tailwind::$H1;
            $title    = \__('CherryLink Pro AI 🍒', 'cherrylink');
            ?>
            <div class="<?php echo \esc_attr($h1_class); ?>"><?php echo \esc_html($title); ?></div>
        </div>

        <?php $menu->display(); ?>
    </div>
<?php
