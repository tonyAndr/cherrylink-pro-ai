<?php
/**
 * Settings-page tab menu.
 *
 * @var array<int, array{title: string, url: string, current: bool}> $items
 */

defined('ABSPATH') || exit;
?>

<ul id="submenu" class="linkateposts-tabs-menu rounded-lg bg-white shadow-xl p-2 " style="display: block">
<?php foreach ($items as $item) : ?>
<li style="display: inline"><a href="<?php echo esc_url($item['url']); ?>"<?php echo $item['current'] ? ' class="current"' : ''; ?> style="display: inline"><?php echo esc_html($item['title']); ?></a></li>
<?php endforeach; ?>
</ul>
<?php
