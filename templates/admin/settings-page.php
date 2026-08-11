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
            <div class="<?= \CherryLink\Support\Tailwind::$H1 ?>">CherryLink Pro 🍒</div>
        </div>

        <?php $menu->display(); ?>
    </div>
<?php
