<?php

// Link-card strtr() template. Byte-extracted from the legacy inline templates
// in cherrylink_main.php; consumed via View::string() + strtr(link_fields).
// Do not reformat: whitespace is significant to the rendered output.

return '
		<div class="linkate-item-container">
			<div class="linkate-controls">
				<div class="link-counter" title="' . esc_attr__('Найдено в тексте / переход к ссылке', 'cherrylink') . '">0</div>
				<div class="link-preview" title="' . esc_attr__('Редактировать статью в новой вкладке', 'cherrylink') . '"></div>
				
			</div>
			<div class="linkate-link" title="' . esc_attr__('Нажмите для вставки в текст', 'cherrylink') . '" data-url="{url}" data-title="{title}" data-category="{categorynames}" data-date="{date}" data-author="{author}" data-postid="{postid}" data-imagesrc="{imagesrc}" data-anons="{anons}" data-suggestions="{suggestions}"><span class="link-title" >{title}</span></div>
			<div class="link-right-controls"><div class="link-individual-stats-income" title="' . esc_attr__('Сколько раз сослались на эту статью', 'cherrylink') . '">?</div><div class="link-individual-stats-out" title="' . esc_attr__('Сколько исходящих ссылок содержит статья', 'cherrylink') . '">?</div><div class="link-suggestions" title="' . esc_attr__('Подсказка', 'cherrylink') . '"></div></div></div>';
