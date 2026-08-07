<?php

// Link-card strtr() template. Byte-extracted from the legacy inline templates
// in cherrylink_main.php; consumed via View::string() + strtr(link_fields).
// Do not reformat: whitespace is significant to the rendered output.

return '
		<div class="linkate-item-container">
			<div class="linkate-controls">
				<div class="link-counter" title="Найдено в тексте / переход к ссылке">0</div>
				<div class="link-preview" title="Редактировать статью в новой вкладке"></div>
				
			</div>
			<div class="linkate-link" title="Нажмите для вставки в текст" data-url="{url}" data-title="{title}" data-category="{categorynames}" data-date="{date}" data-author="{author}" data-postid="{postid}" data-imagesrc="{imagesrc}" data-anons="{anons}" data-suggestions="{suggestions}"><span class="link-title" >{title}</span></div>
			<div class="link-right-controls"><div class="link-individual-stats-income" title="Сколько раз сослались на эту статью">?</div><div class="link-individual-stats-out" title="Сколько исходящих ссылок содержит статья">?</div><div class="link-suggestions" title="Подсказка"></div></div></div>';
