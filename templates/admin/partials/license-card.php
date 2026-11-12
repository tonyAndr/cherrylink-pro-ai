<?php
/**
 * License key card (main subpage).
 *
 * @var bool   $info            whether a valid license is active
 * @var string $license_class   card wrapper css classes
 * @var string $license_header  pre-built header HTML (echoed raw)
 * @var array  $options         linkate-posts options (for hash_field)
 * @var string $key_error       human-readable activation error, or ''
 * @var string $validation_status pending / valid / error
 * @var string $plan_line       "Plan: free · N of M indexed", or ''
 * @var string $upgrade_url     upgrade link (placeholder until the site exists)
 */

defined('ABSPATH') || exit;
?>
    <div class="p-6 rounded-lg shadow-xl <?php echo $license_class; ?>">
        <?php echo $license_header; ?>
        <?php if ($info) : ?>
            <p><?php esc_html_e('Действует лицензия на текущий домен, ключ скрыт в целях безопасности.', 'cherrylink'); ?></p>
            <?php if (! empty($plan_line)) : ?>
                <p><strong><?php echo esc_html($plan_line); ?></strong> · <a target="_blank" rel="noopener" href="<?php echo esc_url($upgrade_url); ?>"><?php esc_html_e('Расширить', 'cherrylink'); ?></a></p>
            <?php endif; ?>
            <form method="post" action="">
                <input type="submit" class="<?= \CherryLink\Support\Tailwind::$BTN_NORMAL ?> " name="remove_license" value="<?php _e('Сбросить лицензию', 'cherrylink') ?>" />
                <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
            </form>
        <?php else : ?>
            <form method="post" action="">
                <label class="font-bold" for="hash_field"><?php _e('Ваш ключ:', 'cherrylink') ?></label>
                <br>
                <input type="text" name="hash_field" id="hash_field" required class=" mt-2 <?= \CherryLink\Support\Tailwind::$INP_BASE ?>" value="<?php echo htmlspecialchars(stripslashes($options['hash_field'])); ?>">
                <?php
                if ($key_error) {
                ?>
                    <p style="font-weight:bold; color:red"><?= $key_error ?></p>
                <?php
                }
                ?>
                <br>
                <input type="submit" class="<?= \CherryLink\Support\Tailwind::$BTN_SAVE ?> " name="update_license" value="<?php _e('Сохранить', 'cherrylink') ?>" />
                <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
            </form>

        <?php endif; ?>
        <?php if ($info && $key_error && in_array($validation_status, ['pending', 'error'], true)) : ?>
            <p><?= $key_error ?></p>
        <?php endif; ?>
        <?php if ($validation_status === 'pending' || $validation_status === 'error') : ?>
            <form method="post" action="" class="mt-3">
                <input type="submit" class="<?= \CherryLink\Support\Tailwind::$BTN_NORMAL ?>" name="retry_license" value="<?php esc_attr_e('Проверить ключ повторно', 'cherrylink'); ?>" />
                <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
            </form>
        <?php endif; ?>
    </div>
<?php
