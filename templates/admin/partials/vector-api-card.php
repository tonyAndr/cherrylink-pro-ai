<?php
/**
 * Cherry Vector API URL/key card (main subpage).
 *
 * @var string $current_url
 * @var string $current_key
 */

defined('ABSPATH') || exit;
?>
    <div class="<?= CL_TWC::$CARD ?>">
        <h2 class="<?= CL_TWC::$H2 ?>">Cherry Vector API</h2>
        <p class="text-sm text-gray-500 mb-2">
            URL и ключ доступа к вашему Cherry Vector API.<br>
            Ключ должен совпадать с <code>ADMIN_KEY</code> в <code>.env</code> на сервере.
        </p>
        <form method="post" action="">
            <label class="font-bold" for="vector_api_url">URL:</label><br>
            <input type="url" name="vector_api_url" id="vector_api_url" class="mt-2 <?= CL_TWC::$INP_BASE ?>"
                value="<?= esc_attr($current_url) ?>" placeholder="https://your-api.example.com">
            <br><br>
            <label class="font-bold" for="vector_api_key">API Key:</label><br>
            <input type="text" name="vector_api_key" id="vector_api_key" class="mt-2 <?= CL_TWC::$INP_BASE ?>"
                value="<?= esc_attr($current_key) ?>" placeholder="your-admin-key">
            <br>
            <input type="submit" class="<?= CL_TWC::$BTN_SAVE ?> mt-3" name="update_vector_api" value="Сохранить">
            <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
        </form>
    </div>
<?php
