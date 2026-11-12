<?php
/**
 * Setup wizard (shown on the CherryLink settings page, no activation redirect).
 *
 * @var int      $step         1 connect, 2 indexing, 3 summary
 * @var string   $connect_url  admin-post.php URL
 * @var string   $action       admin-post action name
 * @var string   $key_url      "I already have a key" target
 * @var string   $step2_url
 * @var string   $step3_url
 * @var string   $done_url     normal settings page
 * @var string   $terms_url
 * @var string   $privacy_url
 * @var string[] $post_types   indexed post types
 * @var string   $expert_url   where post types / filters are configured
 * @var string   $plan         plan line or ''
 * @var int|null $indexed      cached indexed-post count
 */

defined('ABSPATH') || exit;

$card = \CherryLink\Support\Tailwind::$CARD;
$h2   = \CherryLink\Support\Tailwind::$H2;
?>
    <div class="linkateposts-tab-content pt-3" style="max-width:720px">
        <?php if ($step === 1) : ?>
            <div class="<?php echo esc_attr($card); ?>">
                <h2 class="<?php echo esc_attr($h2); ?>"><?php esc_html_e('Шаг 1 из 3. Подключите CherryLink', 'cherrylink'); ?></h2>
                <p><?php esc_html_e('Для подбора ссылок плагин отправляет на серверы CherryLink (ЕС) заголовки, текст и адреса ваших записей. Данные используются только для расчёта ссылок. Пока вы не нажмёте «Подключить», плагин ничего никуда не отправляет.', 'cherrylink'); ?></p>
                <p>
                    <a target="_blank" rel="noopener" href="<?php echo esc_url($terms_url); ?>"><?php esc_html_e('Условия использования', 'cherrylink'); ?></a> ·
                    <a target="_blank" rel="noopener" href="<?php echo esc_url($privacy_url); ?>"><?php esc_html_e('Политика конфиденциальности', 'cherrylink'); ?></a>
                </p>
                <form method="post" action="<?php echo esc_url($connect_url); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
                    <?php wp_nonce_field($action); ?>
                    <button type="submit" class="<?php echo esc_attr(\CherryLink\Support\Tailwind::$BTN_SCAN); ?>"><?php esc_html_e('Подключить', 'cherrylink'); ?></button>
                    <a href="<?php echo esc_url($key_url); ?>"><?php esc_html_e('У меня уже есть ключ', 'cherrylink'); ?></a>
                </form>
            </div>
        <?php elseif ($step === 2) : ?>
            <div class="<?php echo esc_attr($card); ?>">
                <h2 class="<?php echo esc_attr($h2); ?>"><?php esc_html_e('Шаг 2 из 3. Индексация', 'cherrylink'); ?></h2>
                <p><?php esc_html_e('Типы записей для индексации:', 'cherrylink'); ?> <code><?php echo esc_html(implode(', ', $post_types)); ?></code>
                    (<a href="<?php echo esc_url($expert_url); ?>"><?php esc_html_e('изменить', 'cherrylink'); ?></a>)</p>
                <?php \CherryLink\Admin\SettingsPage::indexProgress(); ?>
                <p><a class="<?php echo esc_attr(\CherryLink\Support\Tailwind::$BTN_NORMAL); ?>" href="<?php echo esc_url($step3_url); ?>"><?php esc_html_e('Далее', 'cherrylink'); ?></a></p>
            </div>
        <?php else : ?>
            <div class="<?php echo esc_attr($card); ?>">
                <h2 class="<?php echo esc_attr($h2); ?>"><?php esc_html_e('Шаг 3 из 3. Готово', 'cherrylink'); ?></h2>
                <?php if ($plan !== '') : ?><p><strong><?php echo esc_html($plan); ?></strong></p><?php endif; ?>
                <?php if ($indexed !== null) : ?>
                    <p><?php
                    /* translators: %s: number of indexed posts */
                    printf(esc_html__('Проиндексировано записей: %s', 'cherrylink'), esc_html(number_format_i18n((int) $indexed)));
                    ?></p>
                <?php endif; ?>
                <p><?php esc_html_e('Откройте любую запись в редакторе: панель CherryLink предложит ссылки.', 'cherrylink'); ?></p>
                <a class="<?php echo esc_attr(\CherryLink\Support\Tailwind::$BTN_ACTION); ?>" href="<?php echo esc_url($done_url); ?>"><?php esc_html_e('Перейти к настройкам', 'cherrylink'); ?></a>
            </div>
        <?php endif; ?>
    </div>
<?php
