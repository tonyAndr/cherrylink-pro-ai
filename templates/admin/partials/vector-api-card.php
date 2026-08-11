<?php
/**
 * Cherry Vector API URL/key card (main subpage).
 *
 * @var string $current_url
 * @var string $current_key
 */

defined('ABSPATH') || exit;
?>
    <div class="<?= \CherryLink\Support\Tailwind::$CARD ?>">
        <h2 class="<?= \CherryLink\Support\Tailwind::$H2 ?>">Cherry Vector API</h2>
        <p class="text-sm text-gray-500 mb-2">
            <?php esc_html_e('URL и ключ доступа к вашему Cherry Vector API.', 'cherrylink'); ?><br>
            <?php
            /* translators: 1: ADMIN_KEY (code), 2: .env (code) */
            printf(esc_html__('Ключ должен совпадать с %1$s в %2$s на сервере.', 'cherrylink'), '<code>ADMIN_KEY</code>', '<code>.env</code>');
            ?>
        </p>
        <form method="post" action="">
            <label class="font-bold" for="vector_api_url">URL:</label><br>
            <input type="url" name="vector_api_url" id="vector_api_url" class="mt-2 <?= \CherryLink\Support\Tailwind::$INP_BASE ?>"
                value="<?= esc_attr($current_url) ?>" placeholder="https://your-api.example.com">
            <br><br>
            <label class="font-bold" for="vector_api_key">API Key:</label><br>
            <input type="text" name="vector_api_key" id="vector_api_key" class="mt-2 <?= \CherryLink\Support\Tailwind::$INP_BASE ?>"
                value="<?= esc_attr($current_key) ?>" placeholder="your-admin-key">
            <br>
            <input type="submit" class="<?= \CherryLink\Support\Tailwind::$BTN_SAVE ?> mt-3" name="update_vector_api" value="<?php esc_attr_e('Сохранить', 'cherrylink'); ?>">
            <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
        </form>
    </div>
<?php
