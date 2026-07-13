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

    function sync_is_active(s) { return s && s.status === 'running'; }
    function sync_show_running() { $('.button-reindex').hide(); $('.button-reindex-stop').show(); $('#reindex_progress').show().prop('max', 100); }
    function sync_show_idle()    { $('.button-reindex').show(); $('.button-reindex-stop').hide(); }
    function sync_stop_polling() { if (sync_poll_timer) { clearInterval(sync_poll_timer); sync_poll_timer = null; } }
    function sync_start_polling(){ sync_stop_polling(); sync_poll_timer = setInterval(sync_poll, SYNC_POLL_MS); }

    $('.button-reindex').on("click", function (e) {
        e.preventDefault();
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
    });

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
            sync_show_idle();
            $('#reindex_progress').val(100).hide();
            $('#reindex_progress_text').html(`Сканирование завершено! Обновлено: ${state.indexed || 0}, удалено: ${state.deleted || 0}. Обновите страницу.`);
            $("#cherry_index_status").html(" обновите страницу...");
            $("#cherry_scheme_status").html(" обновите страницу...");
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

    // Get overused words after reindex
    function index_get_overused_words() {
        let ajax_data = 'action=linkate_last_index_overused_words';

        $.ajax({
            type: "POST",
            url: ajaxurl,
            data: ajax_data,
            datatype: 'json',
            success: function (response) {
                let prog = JSON.parse(response);
                if (prog['common_words'] && prog['common_words'].length > 0) {
                    var w = confirm('Найдены общие слова, которые можно добавить в список стоп-слов. Желаете посмотреть?');
                    if (w) {
                        if (!document.querySelector('#spoiler_stop').checked) {
                            $("#label_spoiler_stop").click();
                        }

                        var output_string = '<h2>Самые часто используемые слова на вашем сайте</h2><p>Формат - <strong>слово: количество использований</strong>.</p><p><strong>Нажмите на слова</strong>, которые хотите добавить в черный список.</p><ol>';
                        prog['common_words'].forEach(function (item) {
                            output_string += "<li title='Нажмите, чтобы добавить в стоп-лист' data-stemm='" + item.word + "' class='index-stopsugg-add'><strong>" + item.word + "</strong>: " + item.count + "</li>";
                        });
                        output_string += "</ol>";
                        $("#index_stopwords_suggestions").html(output_string);
                        $("#index_stopwords_suggestions").show();
                        document.querySelector('#index_stopwords_suggestions').scrollIntoView({
                            behavior: 'smooth' 
                        });

                        bind_action_add_overused_word_to_stoplist();
                    }
                }
            }
        });
    }

    function bind_action_add_overused_word_to_stoplist () {
        // quick add suggestions
        $(".index-stopsugg-add").click(function (event) {
            event.preventDefault();

            let words = [$(this).attr('data-stemm').trim()];
            let ajax_data = {
                words: words,
                action: 'linkate_add_stopwords',
                is_white: 0,
                is_stemm: 0
            };
            $(this).remove();
            $.ajax({
                type: "POST",
                url: ajaxurl,
                data: ajax_data,
                datatype: 'json',
                success: function (response) {
                }
            });

        });
    }

    function handle_errors (error_msg, error_details) {
        console.log(error_msg);
        console.log(error_details);
        sync_stop_polling();
        sync_show_idle();
        $('#reindex_progress').hide().val(0);
        $('#reindex_progress_text').html(`Что-то пошло не так при сканировании. Проверьте консоль браузера (F12), чтобы узнать больше.`);
    }
});