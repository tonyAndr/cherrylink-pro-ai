<?php

// Link-card strtr() template. Byte-extracted from the legacy inline templates
// in cherrylink_main.php; consumed via View::string() + strtr(link_fields).
// Do not reformat: whitespace is significant to the rendered output.

return '"data-url":"{url}","data-title":"{title}","data-category":"{categorynames}","data-date":"{date}","data-author":"{author}","data-postid":"{postid}","data-imagesrc":"{imagesrc}","data-anons":"{anons}","data-suggestions":"{suggestions}"';
