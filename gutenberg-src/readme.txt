Этот репо - обертка для основного плагина CherryLink для редактора Gutenberg.

В основном плагине задан путь до файла /build/index.js если мы на локалке в разработке, иначе путь меняется на локальный cherrylink/js/cherrylink-gurenberg.js.

Для разработки, если внесены изменения, запустить npm run-script build - создастся файл /build/index.js

Для релиза, переименовать этот файл, закинуть в путь cherrylink/js/cherrylink-gurenberg.js.