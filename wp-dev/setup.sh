#!/usr/bin/env bash
set -euo pipefail

WP="wp --allow-root --path=/var/www/html"

# Wait for the WordPress container to write wp-config.php (signal that it initialised)
echo "Waiting for wp-config.php…"
for i in $(seq 1 60); do
  if [ -f /var/www/html/wp-config.php ]; then
    echo "wp-config.php found."
    break
  fi
  echo "  attempt $i/60 — sleeping 3s…"
  sleep 3
done

if [ ! -f /var/www/html/wp-config.php ]; then
  echo "ERROR: wp-config.php never appeared." >&2
  exit 1
fi

# Give MySQL a moment to settle after wp-config exists
sleep 5

if ! $WP core is-installed --quiet 2>/dev/null; then
  echo "Running wp core install…"
  $WP core install \
    --url="http://localhost:8088" \
    --title="CherryLink Dev" \
    --admin_user="admin" \
    --admin_password="admin" \
    --admin_email="admin@example.com" \
    --skip-email
else
  echo "WordPress already installed, skipping."
fi

echo "Activating cherrylink-pro-ai…"
$WP plugin activate cherrylink-pro-ai 2>&1 || echo "Warning: plugin activation issue (check PHP errors above)"

$WP rewrite structure '/%postname%/' --hard 2>/dev/null || true

# Seed articles only if fewer than 6 published posts exist
POST_COUNT=$($WP post list --post_status=publish --post_type=post --format=count 2>/dev/null || echo 0)
if [ "$POST_COUNT" -lt 6 ]; then
  echo "Seeding articles…"

  $WP post create \
    --post_title="Как выбрать хостинг для сайта" \
    --post_name="kak-vybrat-hosting" \
    --post_status=publish --post_type=post \
    --post_content="Выбор хостинга — один из первых шагов при создании сайта. Нужно учитывать скорость работы серверов, надёжность и стоимость тарифного плана. Виртуальный хостинг подходит для небольших проектов и блогов. VPS и выделенный сервер используют для нагруженных сайтов с большим трафиком. Обратите внимание на поддержку PHP нужной версии, наличие SSL и регулярные бэкапы."

  $WP post create \
    --post_title="WordPress vs Tilda: что выбрать для бизнеса" \
    --post_name="wordpress-vs-tilda" \
    --post_status=publish --post_type=post \
    --post_content="WordPress — самая популярная CMS в мире, подходит для блогов, интернет-магазинов и корпоративных сайтов. Tilda — конструктор сайтов с удобным визуальным редактором, ориентированный на лендинги. WordPress даёт полный контроль над кодом и большой выбор плагинов, но требует технических знаний для настройки и поддержки. Tilda проще в освоении, но ограничена по функциональности."

  $WP post create \
    --post_title="SEO-оптимизация статей: полное руководство" \
    --post_name="seo-optimizaciya-statej" \
    --post_status=publish --post_type=post \
    --post_content="SEO-оптимизация текста начинается с исследования ключевых слов. Используйте целевые запросы в заголовках, мета-описании и первых абзацах статьи. Важную роль играет перелинковка: внутренние ссылки помогают поисковым роботам обходить сайт и распределяют ссылочный вес между страницами. Качественный уникальный контент, правильная структура заголовков H1–H3 и быстрая загрузка страниц — основа хорошего ранжирования."

  $WP post create \
    --post_title="Внутренняя перелинковка сайта: зачем нужна и как сделать" \
    --post_name="vnutrennyaya-perelinkkovka" \
    --post_status=publish --post_type=post \
    --post_content="Внутренняя перелинковка — это система ссылок между страницами одного сайта. Она помогает посетителям находить связанные материалы и увеличивает глубину просмотра. С точки зрения SEO перелинковка передаёт ссылочный вес и ускоряет индексацию новых страниц. Анкорный текст ссылки должен содержать ключевые слова и естественно вписываться в контекст. Рекомендуется добавлять 3–5 внутренних ссылок на каждую статью."

  $WP post create \
    --post_title="Скорость загрузки сайта: как улучшить показатели" \
    --post_name="skorost-zagruzki-sajta" \
    --post_status=publish --post_type=post \
    --post_content="Скорость загрузки напрямую влияет на поведенческие факторы и позиции в поиске. Оптимизируйте изображения: сжимайте их без потери качества, используйте формат WebP. Подключите кэширование на уровне сервера и браузера. Минифицируйте CSS и JavaScript, уберите лишние плагины. Хороший хостинг с SSD-дисками и CDN значительно сокращает время ответа сервера."

  $WP post create \
    --post_title="Как писать мета-описания для страниц сайта" \
    --post_name="meta-opisaniya" \
    --post_status=publish --post_type=post \
    --post_content="Мета-описание — это краткий анонс страницы длиной 150–160 символов, который отображается в результатах поиска под заголовком. Хорошее мета-описание содержит ключевой запрос, призыв к действию и уникальное преимущество. Не дублируйте одно мета-описание на несколько страниц: каждая страница должна иметь уникальный сниппет. Хотя мета-описание напрямую не влияет на ранжирование, оно повышает кликабельность в выдаче."

  echo "Articles seeded."
else
  echo "Articles already present ($POST_COUNT posts), skipping seed."
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  WordPress:  http://localhost:8088"
echo "  Admin:      http://localhost:8088/wp-admin"
echo "  User/pass:  admin / admin"
echo "  Vector API: http://api:8000 (inside containers)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
