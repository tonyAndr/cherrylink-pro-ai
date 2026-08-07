<?php
/**
 * License key card (main subpage).
 *
 * @var bool   $info            whether a valid license is active
 * @var string $license_class   card wrapper css classes
 * @var string $license_header  pre-built header HTML (echoed raw)
 * @var array  $options         linkate-posts options (for hash_field)
 * @var string $key_error       human-readable activation error, or ''
 */

defined('ABSPATH') || exit;
?>
    <div class="p-6 rounded-lg shadow-xl <?php echo $license_class; ?>">
        <?php echo $license_header; ?>
        <?php if ($info) : ?>
            <p>Действует лицензия на текущий домен, ключ скрыт в целях безопасности.</p>
            <form method="post" action="">
                <input type="submit" class="<?= CL_TWC::$BTN_NORMAL ?> " name="remove_license" value="<?php _e('Сбросить лицензию', CHERRYLINK_TEXT_DOMAIN) ?>" />
                <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
            </form>
        <?php else : ?>
            <form method="post" action="">
                <label class="font-bold" for="hash_field"><?php _e('Ваш ключ:', CHERRYLINK_TEXT_DOMAIN) ?></label>
                <br>
                <input type="text" name="hash_field" id="hash_field" required class=" mt-2 <?= CL_TWC::$INP_BASE ?>" value="<?php echo htmlspecialchars(stripslashes($options['hash_field'])); ?>">
                <?php
                if ($key_error) {
                ?>
                    <p style="font-weight:bold; color:red"><?= $key_error ?></p>
                <?php
                }
                ?>
                <br>
                <input type="submit" class="<?= CL_TWC::$BTN_SAVE ?> " name="update_license" value="<?php _e('Сохранить', CHERRYLINK_TEXT_DOMAIN) ?>" />
                <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
            </form>
        <?php endif; ?>
    </div>
<?php
