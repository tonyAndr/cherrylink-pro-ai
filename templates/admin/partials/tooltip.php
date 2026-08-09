<?php
/**
 * Help "?" tooltip linking into the online manual.
 *
 * @var string $anchor  fragment id on the manual page (page_id=1699#<anchor>)
 */

defined('ABSPATH') || exit;
?>
<div class='cherry-adm-tooltip'><a target="_blank" href="https://seocherry.ru/?page_id=1699#<?= esc_attr($anchor) ?>" title="<?php esc_attr_e('Перейти к справке', 'cherrylink'); ?>"><img src='<?php echo esc_url(CHERRYLINK_DIR_URL); ?>img/question-mark.png'></a>
</div>
