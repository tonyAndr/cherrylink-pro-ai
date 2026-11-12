<?php
/**
 * CherryLink settings page shell: header + tabbed subpage host.
 *
 * @var \CherryLink\Admin\Subpages $menu  preconfigured subpage router
 * @var array|null $wizard           setup wizard data (null = normal tabbed page)
 * @var string $connect_notice       escaped Connect error message, or ''
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

        <?php if (! empty($connect_notice)) : ?>
            <div class="notice notice-error"><p><?php echo $connect_notice; ?></p></div>
        <?php endif; ?>

        <?php if (! empty($wizard)) : ?>
            <?php echo (new \CherryLink\Support\View())->render('admin/wizard', $wizard); ?>
        <?php else : ?>
            <?php $menu->display(); ?>
        <?php endif; ?>
    </div>
<?php
