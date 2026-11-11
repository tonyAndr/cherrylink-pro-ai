jQuery(document).ready(function ($) {

    const { __, sprintf } = wp.i18n;

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

    // If the server state stops changing while a scan is "running", the scheduler is
    // not processing the queue (WP-Cron disabled, no server cron, loopback blocked).
    // Say so instead of spinning forever with no explanation.
    var sync_sig = null, sync_sig_since = 0;
    const SYNC_STALL_MS = 20000;
    function sync_track_progress(state) {
        var sig = [state.status, state.phase, state.offset, state.indexed, state.links,
            state.deleted, state.retry_attempt, state.accepted, state.vectors_applied].join('|');
        if (sig !== sync_sig) { sync_sig = sig; sync_sig_since = Date.now(); return false; }
        return sync_is_active(state) && (Date.now() - sync_sig_since) > SYNC_STALL_MS;
    }

    function sync_is_active(s) { return s && s.status === 'running'; }
    function sync_show_running() { $('.button-reindex').hide(); $('.button-reindex-stop').show(); $('#reindex_progress').show().prop('max', 100); }
    function sync_show_idle()    { $('.button-reindex').show(); $('.button-reindex-stop').hide(); }
    // The scan-completed prompt is rendered server-side on page load. Once a scan
    // finishes through AJAX it is stale, so drop it without a manual reload.
    function sync_hide_scan_notice() {
        $('[data-cherrylink-scan-notice]').remove();
    }

    function sync_stop_polling() { if (sync_poll_timer) { clearInterval(sync_poll_timer); sync_poll_timer = null; } }
    function sync_start_polling(){ sync_stop_polling(); sync_poll_timer = setInterval(sync_poll, SYNC_POLL_MS); }

    function sync_do_start() {
        index_serialized_form = $("#options_form").serialize();
        sync_show_running();
        $('#reindex_progress').val(0);
        $('#reindex_progress_text').html(__("Запуск фонового сканирования…", "cherrylink"));
        $.ajax({
            type: "POST", url: ajaxurl, dataType: 'json',
            data: index_serialized_form + '&action=linkate_sync_start&_ajax_nonce=' + cherry_ajax.nonce,
            success: function (state) { sync_render(state); sync_start_polling(); },
            error: function (x, t, err) { handle_errors(err, x.responseText); }
        });
    }
    $('.button-reindex').on("click", function (e) { e.preventDefault(); sync_do_start(); });
    // "Scan again" link shown in the completion message (button stays hidden on done).
    $(document).on('click', '#cl-rescan', function (e) { e.preventDefault(); sync_do_start(); });

    // Pull fresh readiness data after a scan and update the card in place (no page reload).
    var cl_status_refresh_inflight = false;
    function sync_refresh_status(force) {
        if (cl_status_refresh_inflight) return;
        cl_status_refresh_inflight = true;
        $.ajax({
            type: "POST", url: ajaxurl, dataType: 'json', data: 'action=linkate_index_status_data&_ajax_nonce=' + cherry_ajax.nonce + (force ? '&force=1' : ''),
            timeout: 6000,
            success: function (d) {
                if (!d) return;
                $('#cherry_index_status').html(d.index_status_text || '');
                $('#cherry_scheme_status').html(d.scheme_status_text || '');
                $('#cherry_process_badge').html(d.badge_html || '');
                $('#cherry_lastscan_status').html(d.last_scan_text || '');
                cl_localize_times('#cherry_lastscan_status');
            },
            complete: function () { cl_status_refresh_inflight = false; }
        });
    }

    $('.button-reindex-stop').on("click", function (e) {
        e.preventDefault();
        $('#reindex_progress_text').html(__("Остановка…", "cherrylink"));
        $.ajax({
            type: "POST", url: ajaxurl, dataType: 'json', data: 'action=linkate_sync_cancel&_ajax_nonce=' + cherry_ajax.nonce,
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
            type: "POST", url: ajaxurl, dataType: 'json', data: 'action=linkate_sync_tick&_ajax_nonce=' + cherry_ajax.nonce,
            complete: function () { tick_in_flight = false; }
        });
    }

    function sync_poll() {
        $.ajax({
            type: "POST", url: ajaxurl, dataType: 'json', data: 'action=linkate_sync_status&_ajax_nonce=' + cherry_ajax.nonce,
            success: function (state) {
                sync_render(state);
                if (sync_is_active(state)) sync_tick();
                else sync_stop_polling();
            },
            error: function (x, t, err) { handle_errors(err, x.responseText); }
        });
    }

    // Async runs report upload and processing separately: a 202 only means the API
    // accepted the payload. Never label upload progress as indexing progress.
    function sync_render_async(state) {
        var done = 0, total = 0, label = '';

        if (state.waiting) {
            sync_show_running();
            $('#reindex_progress_text').html(state.message || __('Ожидание сервера… (можно закрыть страницу)', 'cherrylink'));
            return;
        }
        if (state.uploading) {
            sync_show_running();
            done  = state.accepted || 0;
            total = state.upsert_total || 0;
            label = __('Загрузка на сервер', 'cherrylink');
        } else if (state.processing) {
            sync_show_running();
            done  = state.vectors_applied || 0;
            total = state.vectors_pending || 0;
            total = done + total;
            label = __('Обработка на сервере', 'cherrylink');
        } else if (state.sealed) {
            sync_show_running();
            label = __('Ожидание завершения обработки', 'cherrylink');
        } else {
            sync_show_running();
            label = __('Подготовка сканирования', 'cherrylink');
        }

        if (total > 0) {
            $('#reindex_progress').val(Math.min(100, Math.round(done / total * 100)));
            /* translators: 1: phase label, 2: done count, 3: total count */
            $('#reindex_progress_text').html(sprintf(__('%1$s: %2$d из %3$d… (можно закрыть страницу)', 'cherrylink'), label, done, total));
        } else {
            /* translators: %s: phase label */
            $('#reindex_progress_text').html(sprintf(__('%s… (можно закрыть страницу)', 'cherrylink'), label));
        }
    }

    // Render UI from the server state object.
    function sync_render(state) {
        if (!state || !state.status) return;
        if (state.engine === 'async') {
            sync_render_async(state);
            if (state.status === 'paused' && !state.waiting) {
                sync_show_idle();
                $('#reindex_progress').hide();
                $('#reindex_progress_text').html(
                    state.message || __('Приостановлено.', 'cherrylink') + ' <a href="#" id="cl-rescan">' +
                    __('Продолжить', 'cherrylink') + '</a>'
                );
            } else if (state.status === 'done' || state.status === 'completed_with_errors') {
                $('.button-reindex').hide();
                $('.button-reindex-stop').hide();
                $('#reindex_progress').val(100).hide();
                sync_hide_scan_notice();
                sync_refresh_status();
                if (state.status === 'done') {
                    /* translators: 1: uploaded count, 2: deleted count */
                    $('#reindex_progress_text').html(sprintf(__('Индексация завершена. Загружено: %1$d, удалено: %2$d.', 'cherrylink'), state.uploaded || 0, state.deleted || 0) + ' <a href="#" id="cl-rescan">' + __('Сканировать снова', 'cherrylink') + '</a>');
                } else {
                    /* translators: 1: applied vectors, 2: total items */
                    $('#reindex_progress_text').html(sprintf(__('Индексация завершена с ошибками: обработано %1$d из %2$d.', 'cherrylink'), state.vectors_applied || 0, state.vectors_applied || 0) + ' <a href="#" id="cl-rescan">' + __('Сканировать снова', 'cherrylink') + '</a>');
                }
            } else if (state.status === 'cancelled') {
                sync_show_idle();
                $('#reindex_progress').hide();
                /* translators: %d: accepted count */
                $('#reindex_progress_text').html(sprintf(__('Остановлено. Загружено: %d.', 'cherrylink'), state.accepted || 0));
            } else if (state.status === 'error') {
                sync_show_idle();
                $('#reindex_progress').hide();
                /* translators: %s: error message */
                $('#reindex_progress_text').html(sprintf(__('Ошибка: %s.', 'cherrylink'), state.message || __('неизвестно', 'cherrylink')));
            }
            return;
        }

        if (state.status === 'running') {
            sync_show_running();
            let done = 0, total = state.upsert_total || 0, label = __('Индексация', 'cherrylink');
            if (state.phase === 'upsert')       { done = state.indexed || 0; label = __('Индексация изменённых', 'cherrylink'); }
            else if (state.phase === 'links')   { done = state.offset  || 0; label = __('Построение карты ссылок', 'cherrylink'); }
            else if (state.phase === 'prune')   { label = __('Удаление устаревших', 'cherrylink'); total = 0; }
            else if (state.phase === 'manifest'){ label = __('Получение списка статей', 'cherrylink'); total = 0; }
            if (total > 0) {
                $('#reindex_progress').val(Math.min(100, Math.round(done / total * 100)));
                /* translators: 1: phase label, 2: done count, 3: total count */
                $('#reindex_progress_text').html(sprintf(__('%1$s: %2$d из %3$d… (можно закрыть страницу)', 'cherrylink'), label, done, total));
            } else {
                /* translators: %s: phase label */
                $('#reindex_progress_text').html(sprintf(__('%s… (можно закрыть страницу)', 'cherrylink'), label));
            }
        } else if (state.status === 'done') {
            // Hide both buttons on completion — otherwise a 0-change scan finishes in <1s and
            // the reappearing button makes it look like nothing happened. The "refresh" hint
            // restores a clean state (with the button) on reload.
            $('.button-reindex').hide();
            $('.button-reindex-stop').hide();
            $('#reindex_progress').val(100).hide();
            sync_hide_scan_notice();
            sync_refresh_status();   // live-update the readiness card — no reload needed
            /* translators: 1: updated count, 2: deleted count */
            $('#reindex_progress_text').html(sprintf(__('Сканирование завершено! Обновлено: %1$d, удалено: %2$d.', 'cherrylink'), state.indexed || 0, state.deleted || 0) + ' <a href="#" id="cl-rescan">' + __('Сканировать снова', 'cherrylink') + '</a>');
        } else if (state.status === 'cancelled') {
            sync_show_idle();
            $('#reindex_progress').hide();
            /* translators: %d: number of partially indexed items */
            $('#reindex_progress_text').html(sprintf(__('Остановлено. Частично проиндексировано: %d.', 'cherrylink'), state.indexed || 0));
        } else if (state.status === 'error') {
            sync_show_idle();
            $('#reindex_progress').hide();
            /* translators: %s: error message */
            $('#reindex_progress_text').html(sprintf(__('Ошибка: %s.', 'cherrylink'), state.message || __('неизвестно', 'cherrylink')));
        } else {
            sync_show_idle();
        }
        sync_stall_hint(state);
    }

    function sync_stall_hint(state) {
        if (!sync_track_progress(state)) return;
        $('#reindex_progress_text').append(
            '<br><span class="cl-sync-stall">' +
            __('Очередь не обрабатывается: планировщик WP-Cron не запускается. Проверьте cron или запустите wp action-scheduler run.', 'cherrylink') +
            '</span>'
        );
    }

    // Resume the progress view if a sync is already running (started earlier or in another tab).
    (function () {
        $.ajax({
            type: "POST", url: ajaxurl, dataType: 'json', data: 'action=linkate_sync_status&_ajax_nonce=' + cherry_ajax.nonce,
            success: function (state) {
                if (sync_is_active(state) || state.status === 'paused') { sync_render(state); sync_start_polling(); }
            }
        });
    })();

    /*
    --- === SERVER STATUS / CONNECTIVITY DIAGNOSTICS === ---
    Times a round-trip to the API from this server so we can see the real latency
    (the RU→VPS path). Checks run only when requested by the administrator.
    */
    function cl_srv_escape(value) {
        return $('<div>').text(String(value)).html();
    }

    function cl_srv_fmt_probe(label, pr) {
        if (!pr) return '<li>' + label + ': —</li>';
        var status = pr.ok ? ('OK · ' + cl_srv_escape(pr.ms) + ' ' + __('мс', 'cherrylink')) : (sprintf(__('ОШИБКА (%s)', 'cherrylink'), cl_srv_escape(pr.error || ('HTTP ' + pr.http_code))) + ' · ' + cl_srv_escape(pr.ms) + ' ' + __('мс', 'cherrylink'));
        var extra = '';
        if (pr.body && typeof pr.body === 'object' && typeof pr.body.count !== 'undefined') extra = ' · ' + __('значение:', 'cherrylink') + ' ' + cl_srv_escape(pr.body.count);
        return '<li>' + label + ': <strong>' + status + '</strong>' + extra + '</li>';
    }

    function cl_srv_render(d) {
        var $lines = $('#cl-srv-lines'), $badge = $('#cl-srv-badge');
        if (d && d.pending) {
            $badge.html('<code>[' + __('проверка…', 'cherrylink') + ']</code>');
            return;
        }
        if (!d || !d.probes) {
            $badge.html('<code class="bad-index">[' + __('нет ответа', 'cherrylink') + ']</code>');
            $lines.html('<li>' + __('Не удалось получить данные о состоянии сервера.', 'cherrylink') + '</li>');
            return;
        }
        var p = d.probes;
        var reachable = p.health && p.health.ok;
        var slow = p.health && p.health.ms > 3000;
        var readTimeout = d.read_timeout_s || 15;

        if (!reachable) $badge.html('<code class="bad-index">[' + __('нет связи с сервером', 'cherrylink') + ']</code>');
        else if (slow)  $badge.html('<code class="bad-index">[' + __('связь медленная', 'cherrylink') + ']</code>');
        else            $badge.html('<code class="good-index">[' + __('связь в норме', 'cherrylink') + ']</code>');

        var html = '';
        html += cl_srv_fmt_probe(__('Доступность сервера', 'cherrylink'), p.health);
        html += cl_srv_fmt_probe(__('Чтение индекса статей', 'cherrylink'), p.documents_count);
        html += cl_srv_fmt_probe(__('Чтение карты ссылок', 'cherrylink'), p.links_count);
        if (reachable && slow) {
            /* translators: %s: read-timeout in seconds */
            html += '<li style="color:#b45309"><strong>' + __('Внимание:', 'cherrylink') + '</strong> ' +
                    sprintf(__('ответы приходят дольше 3 секунд. На медленном канале плагин может показывать «0» и подсказки могут не грузиться (таймаут чтения — %s с).', 'cherrylink'), readTimeout) + '</li>';
        }
        $lines.html(html);
    }

    var cl_srv_check_inflight = false;
    function cl_srv_check() {
        if (cl_srv_check_inflight) return;
        cl_srv_check_inflight = true;
        $('#cl-srv-recheck').prop('disabled', true).text(__('Проверяем…', 'cherrylink'));
        $('#cl-srv-badge').html('<code>[' + __('проверка…', 'cherrylink') + ']</code>');
        $.ajax({
            type: 'POST', url: ajaxurl, dataType: 'json', data: 'action=cherrylink_server_status&_ajax_nonce=' + cherry_ajax.nonce,
            timeout: 9000,
            success: function (d) {
                cl_srv_render(d);
                // The manual check also invalidates the readiness snapshot; refresh the
                // card so a cached "no connection" cannot survive the successful probe.
                sync_refresh_status(true);
            },
            error: function (x, t, err) {
                console.log('[CherryLink] server status request failed:', err);
                cl_srv_render(null);
                sync_refresh_status(true);
            },
            complete: function () {
                cl_srv_check_inflight = false;
                $('#cl-srv-recheck').prop('disabled', false).text(__('Проверить связь', 'cherrylink'));
            }
        });
    }
    $(document).on('click', '#cl-srv-recheck', function (e) { e.preventDefault(); cl_srv_check(); });

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
        $('#reindex_progress_text').html(__('Что-то пошло не так при сканировании. Проверьте консоль браузера (F12), чтобы узнать больше.', 'cherrylink'));
    }
});
