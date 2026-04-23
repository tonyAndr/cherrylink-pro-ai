jQuery(document).ready(function ($) {

	/*
	--- === REINDEX === --- 
	*/

    let index_interval_check, index_serialized_form;
    let index_offset = 0, index_limit = 20, index_posts_count = 0, index_in_progress = false, php_execution_time = 0;
    let ajax_index_error_occured = false;

    $('.button-reindex').on("click", function (e) {
        e.preventDefault();
        index_serialized_form = $("#options_form").serialize();
        $('#reindex_progress').show();
        $('.button-reindex').hide();
        $("input").prop('disabled', true);
        $('#reindex_progress_text').html("Сканирование начинается...");
        index_get_posts_count();
        php_execution_time = 0;
        console.time('overall_time')
    })

    // Get posts count to know how many requests we have to make
    function index_get_posts_count() {
        let ajax_data = index_serialized_form + '&action=linkate_get_posts_count_reindex';

        $.ajax({
            type: "POST",
            url: ajaxurl,
            data: ajax_data,
            datatype: 'text',
            success: function (response) {
                // response = JSON.parse(response);
                console.log("Starting process with " + response + " posts found");
                index_posts_count = parseInt(response);
                // update stats_posts_count
                index_interval_check = setInterval(index_process_next, 200);
            },
            error: function (jqXHR, textStatus, errorThrown ) {
                handle_errors(errorThrown, jqXHR.responseText);
            }
        });
    }

    // Process next batch of posts
    function index_process_next() {
        // finish
        if (index_offset >= index_posts_count) {
            console.timeEnd('overall_time');
            console.log('PHP execution time: ' + php_execution_time * 1000 + ' ms');
            clearInterval(index_interval_check);

            $('#reindex_progress').hide();
            $('#reindex_progress').val(0);

            // $('.button-reindex').show();
            $("input").prop('disabled', false);
            console.log("Index created successfully");

            let output = `Сканирование завершено успешно!`
            $('#reindex_progress_text').html(output);
            // index_get_overused_words();
            index_offset = 0;

            $("#cherry_index_status").html(" обновите страницу...")
            $("#cherry_scheme_status").html(" обновите страницу...")
            return;
        }

        // Stop on error
        if (ajax_index_error_occured) {
            clearInterval(index_interval_check);
            $('#reindex_progress').hide();
            $('#reindex_progress').val(0);

            $('.button-reindex').show();
            $("input").prop('disabled', false);
            console.log("Index creation failed");

            let output = `Что-то пошло не так при сканировании. Проверьте консоль браузера (F12), чтобы узнать больше.`
            $('#reindex_progress_text').html(output);
            return;
        }

        // Skip if in progress
        if (index_in_progress)
            return;

        // Process next batch
        let ajax_data = index_serialized_form
            + '&action=linkate_ajax_call_reindex'
            + '&index_offset=' + index_offset
            + '&batch_size=' + index_limit
            + '&index_posts_count=' + index_posts_count;

        index_in_progress = true;
        $.ajax({
            type: "POST",
            url: ajaxurl,
            data: ajax_data,
            datatype: 'json',
            success: function (response) {
                response = JSON.parse(response);
                console.log(response);
                if (response.status === 'OK' || response.status === 'DONE') {
                    if (response.time) {
                        php_execution_time += parseFloat(response.time);
                    }
                    index_offset += index_limit;
                    if (response.status === 'DONE') {
                        index_posts_count = 0;
                    }
                    index_in_progress = false;
                    index_update_progress();
                } else {
                    handle_errors("WPDB_ERROR: " + response.wpdb_error, "WPDB_QUERY: " + response.wpdb_query);
                }
            },
            error: function (jqXHR, textStatus, errorThrown ) {
                handle_errors(errorThrown, jqXHR.responseText);
            }
        });
    }

    // Display index creation progress
    function index_update_progress() {
        let current = 0;
        current = Math.round(index_offset / index_posts_count * 100)
        current = current > 100 ? 100 : current;
        $('#reindex_progress').prop('max', 100);
        $('#reindex_progress').val(current);
        let output = `Обработано ${index_offset} из ${index_posts_count} записей (${current}%)... `
        $('#reindex_progress_text').html(output);
    }

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
        ajax_index_error_occured = true;
        console.log(error_msg);
        console.log(error_details);
    }
});