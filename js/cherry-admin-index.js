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

    function handle_errors (error_msg, error_details) {
        console.log(error_msg);
        console.log(error_details);
        sync_stop_polling();
        sync_show_idle();
        $('#reindex_progress').hide().val(0);
        $('#reindex_progress_text').html(`Что-то пошло не так при сканировании. Проверьте консоль браузера (F12), чтобы узнать больше.`);
    }
});