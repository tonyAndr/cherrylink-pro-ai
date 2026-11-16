<?php
/**
 * Admin notice shown on the settings page when the license is inactive.
 *
 * @var bool   $dirty        whether the link index is out of sync
 * @var string $since        human date the index went dirty (raw, escaped here)
 * @var string $settings_url settings → main subpage URL
 */

defined('ABSPATH') || exit;
?>
<div class="notice notice-warning"><p><strong>CherryLink</strong>: <?php esc_html_e('license inactive — indexing and scanning are paused, links are not being updated.', 'cherrylink-ai'); ?><?php if ($dirty) : ?> <strong><?php esc_html_e('The internal linking index is not synchronized', 'cherrylink-ai'); ?></strong> <?php
    /* translators: %s: human-readable date since which changes have not been indexed */
    printf(esc_html__('(changes since %s are not indexed). Activate your key and run a scan to restore synchronization.', 'cherrylink-ai'), esc_html($since));
?><?php else : ?> <a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Enter your license key', 'cherrylink-ai'); ?></a>.<?php endif; ?></p></div>
<?php
