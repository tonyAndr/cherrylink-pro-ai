jQuery(document).ready(function ($) {

	/*
	--- === REINDEX === --- 
	*/

    // Background incremental sync (Action Scheduler). Start it and walk away — the server
    // processes batches in the background; this page only polls status, so it's safe to
    // refresh or navigate away. Returning to the page resumes the progress view.
    let index_serialized_form;
    let sync_poll_timer = null;
    const SYNC_POLL_MS = 2000;

    // Rewrite server-rendered <time class="cl-localtime" datetime="…Z"> elements to the
    // viewer's browser-local time. Idempotent (skips already-converted nodes). Called on
    // load and after each status refresh, since last_scan_text is re-injected via AJAX.
    function cl_localize_times(root) {
        $(root || document).find('time.cl-localtime:not([data-cl-localized])').each(function () {
            var iso = this.getAttribute('datetime');
            var d = iso ? new Date(iso) : null;
            if (!d || isNaN(d.getTime())) return; // leave server-tz fallback text as-is
            this.textContent = d.toLocaleString();
            this.setAttribute('title', d.toString());
            this.setAttribute('data-cl-localized', '1');
        });
    }
    cl_localize_times();

    function sync_is_active(s) { return s && s.status === 'running'; }
    function sync_show_running() { $('.button-reindex').hide(); $('.button-reindex-stop').show(); $('#reindex_progress').show().prop('max', 100); }
    function sync_show_idle()    { $('.button-reindex').show(); $('.button-reindex-stop').hide(); }
    function sync_stop_polling() { if (sync_poll_timer) { clearInterval(sync_poll_timer); sync_poll_timer = null; } }
    function sync_start_polling(){ sync_stop_polling(); sync_poll_timer = setInterval(sync_poll, SYNC_POLL_MS); }

    function sync_do_start() {
        index_serialized_form = $("#options_form").serialize();
        sync_show_running();
        $('#reindex_progress').val(0);
        $('#reindex_progress_text').html("Запуск фонового сканирования…");
        $.ajax({
            type: "POST", url: ajaxurl, dataType: 'json',
            data: index_serialized_form + '&action=linkate_sync_start',
            success: function (state) { sync_render(state); sync_start_polling(); },
            error: function (x, t, err) { handle_errors(err, x.responseText); }
        });
    }
    $('.button-reindex').on("click", function (e) { e.preventDefault(); sync_do_start(); });
    // "Scan again" link shown in the completion message (button stays hidden on done).
    $(document).on('click', '#cl-rescan', function (e) { e.preventDefault(); sync_do_start(); });

    // Pull fresh readiness data after a scan and update the card in place (no page reload).
    function sync_refresh_status() {
        $.ajax({
            type: "POST", url: ajaxurl, dataType: 'json', data: 'action=linkate_index_status_data',
            success: function (d) {
                if (!d) return;
                $('#cherry_index_status').html(d.index_status_text || '');
                $('#cherry_scheme_status').html(d.scheme_status_text || '');
                $('#cherry_process_badge').html(d.badge_html || '');
                $('#cherry_lastscan_status').html(d.last_scan_text || '');
                cl_localize_times('#cherry_lastscan_status');
            }
        });
    }

    $('.button-reindex-stop').on("click", function (e) {
        e.preventDefault();
        $('#reindex_progress_text').html("Остановка…");
        $.ajax({
            type: "POST", url: ajaxurl, dataType: 'json', data: 'action=linkate_sync_cancel',
            success: function (state) { sync_render(state); if (!sync_is_active(state)) sync_stop_polling(); },
            error: function (x, t, err) { handle_errors(err, x.responseText); }
        });
    });

    // While the scan page is open, drive the Action Scheduler queue ourselves so the
    // background sync advances even with zero site traffic (no WP-Cron tick). Guarded
    // so ticks never overlap. Closing the page falls back to normal cron.
    let tick_in_flight = false;
    function sync_tick() {
        if (tick_in_flight) return;
        tick_in_flight = true;
        $.ajax({
            type: "POST", url: ajaxurl, dataType: 'json', data: 'action=linkate_sync_tick',
            complete: function () { tick_in_flight = false; }
        });
    }

    function sync_poll() {
        $.ajax({
            type: "POST", url: ajaxurl, dataType: 'json', data: 'action=linkate_sync_status',
            success: function (state) {
                sync_render(state);
                if (sync_is_active(state)) sync_tick();
                else sync_stop_polling();
            },
            error: function (x, t, err) { handle_errors(err, x.responseText); }
        });
    }

    // Render UI from the server state object.
    function sync_render(state) {
        if (!state || !state.status) return;
        console.log(state);
        if (state.status === 'running') {
            sync_show_running();
            let done = 0, total = state.upsert_total || 0, label = 'Индексация';
            if (state.phase === 'upsert')      { done = state.indexed || 0; label = 'Индексация изменённых'; }
            else if (state.phase === 'links')  { done = state.offset  || 0; label = 'Построение карты ссылок'; }
            else if (state.phase === 'prune')  { label = 'Удаление устаревших'; total = 0; }
            if (total > 0) {
                $('#reindex_progress').val(Math.min(100, Math.round(done / total * 100)));
                $('#reindex_progress_text').html(`${label}: ${done} из ${total}… (можно закрыть страницу)`);
            } else {
                $('#reindex_progress_text').html(`${label}… (можно закрыть страницу)`);
            }
        } else if (state.status === 'done') {
            // Hide both buttons on completion — otherwise a 0-change scan finishes in <1s and
            // the reappearing button makes it look like nothing happened. The "refresh" hint
            // restores a clean state (with the button) on reload.
            $('.button-reindex').hide();
            $('.button-reindex-stop').hide();
            $('#reindex_progress').val(100).hide();
            sync_refresh_status();   // live-update the readiness card — no reload needed
            $('#reindex_progress_text').html(`Сканирование завершено! Обновлено: ${state.indexed || 0}, удалено: ${state.deleted || 0}. <a href="#" id="cl-rescan">Сканировать снова</a>`);
        } else if (state.status === 'cancelled') {
            sync_show_idle();
            $('#reindex_progress').hide();
            $('#reindex_progress_text').html(`Остановлено. Частично проиндексировано: ${state.indexed || 0}.`);
        } else if (state.status === 'error') {
            sync_show_idle();
            $('#reindex_progress').hide();
            $('#reindex_progress_text').html(`Ошибка: ${state.message || 'неизвестно'}.`);
        } else {
            sync_show_idle();
        }
    }

    // Resume the progress view if a sync is already running (started earlier or in another tab).
    (function () {
        $.ajax({
            type: "POST", url: ajaxurl, dataType: 'json', data: 'action=linkate_sync_status',
            success: function (state) { if (sync_is_active(state)) { sync_render(state); sync_start_polling(); } }
        });
    })();

    /*
    --- === SERVER STATUS / CONNECTIVITY DIAGNOSTICS === ---
    Times a round-trip to the API from this server so we can see the real latency
    (the RU→VPS path). Full payload is dumped to the console for copy/paste.
    */
    function cl_srv_fmt_probe(label, pr) {
        if (!pr) return '<li>' + label + ': —</li>';
        var status = pr.ok ? ('OK · ' + pr.ms + ' мс') : ('ОШИБКА (' + (pr.error || ('HTTP ' + pr.http_code)) + ') · ' + pr.ms + ' мс');
        var extra = '';
        if (pr.body && typeof pr.body === 'object' && typeof pr.body.count !== 'undefined') extra = ' · значение: ' + pr.body.count;
        return '<li>' + label + ': <strong>' + status + '</strong>' + extra + '</li>';
    }

    function cl_srv_render(d) {
        var $lines = $('#cl-srv-lines'), $badge = $('#cl-srv-badge');
        if (!d || !d.probes) {
            $badge.html('<code class="bad-index">[нет ответа]</code>');
            $lines.html('<li>Не удалось получить данные о состоянии сервера.</li>');
            return;
        }
        var p = d.probes;
        var reachable = p.health && p.health.ok;
        var slow = p.health && p.health.ms > 3000;
        var readTimeout = d.read_timeout_s || 15;

        if (!reachable) $badge.html('<code class="bad-index">[нет связи с сервером]</code>');
        else if (slow)  $badge.html('<code class="bad-index">[связь медленная]</code>');
        else            $badge.html('<code class="good-index">[связь в норме]</code>');

        var html = '';
        html += cl_srv_fmt_probe('Доступность сервера', p.health);
        html += cl_srv_fmt_probe('Чтение индекса статей', p.documents_count);
        html += cl_srv_fmt_probe('Чтение карты ссылок', p.links_count);
        if (reachable && slow) {
            html += '<li style="color:#b45309"><strong>Внимание:</strong> ответы приходят дольше 3 секунд. ' +
                    'На медленном канале плагин может показывать «0» и подсказки могут не грузиться ' +
                    '(таймаут чтения — ' + readTimeout + ' с).</li>';
        }
        $lines.html(html);
    }

    function cl_srv_check() {
        $('#cl-srv-recheck').prop('disabled', true).text('Проверяем…');
        $('#cl-srv-badge').html('<code>[проверка…]</code>');
        $.ajax({
            type: 'POST', url: ajaxurl, dataType: 'json', data: 'action=cherrylink_server_status',
            success: function (d) { cl_srv_render(d); },
            error: function (x, t, err) {
                console.log('[CherryLink] server status request failed:', err, x && x.responseText);
                cl_srv_render(null);
            },
            complete: function () { $('#cl-srv-recheck').prop('disabled', false).text('Проверить связь'); }
        });
    }
    $(document).on('click', '#cl-srv-recheck', function (e) { e.preventDefault(); cl_srv_check(); });
    if ($('#cl-server-status-card').length) { cl_srv_check(); }

    // The readiness card renders from a cached snapshot (no API call on page load). Refresh it
    // in the background only when that snapshot is stale, so navigation stays instant and we
    // don't hit the API on every settings view.
    if ($('#cl-readiness-card').data('cl-stale') == 1) { sync_refresh_status(); }

    function handle_errors (error_msg, error_details) {
        console.log(error_msg);
        console.log(error_details);
        sync_stop_polling();
        sync_show_idle();
        $('#reindex_progress').hide().val(0);
        $('#reindex_progress_text').html(`Что-то пошло не так при сканировании. Проверьте консоль браузера (F12), чтобы узнать больше.`);
    }
});