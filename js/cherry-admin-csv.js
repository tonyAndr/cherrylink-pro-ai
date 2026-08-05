jQuery(document).ready(function ($) {

	/*
	--- === STATS === ---
	*/
    let stats_interval_check, stats_serialized_form;
    let stats_admin_preview = false, stats_object = {};
    let stats_offset = 0, stats_limit = 300, stats_posts_count = 0, in_progress = false;
    let process_type = 'csv'; // csv or preview

    $('#generate_csv').on("click", function (e) {
        e.preventDefault();
        console.time('overall_time');
        $('#csv_progress').show();
        $('#generate_csv').hide();
        stats_admin_preview = false;
        stats_offset = 0
        stats_posts_count = 0
        stats_object = {}
        stats_serialized_form = $("#form_generate_csv").serialize();
        $("input").prop('disabled', true);
        process_type = 'csv'
        stats_get_posts_count();
    })

    $('#generate_preview').on("click", function (e) {
        e.preventDefault();
        console.time('overall_time');
        $('#link_check_progress').show();
        $('#generate_preview').hide();
        stats_admin_preview = true;
        stats_offset = 0
        stats_posts_count = 0
        stats_object = {}
        stats_serialized_form = $("#form_generate_stats").serialize() + "&admin_preview_stats=true";
        $("input").prop('disabled', true);
        process_type = 'preview'
        stats_get_posts_count();
    })

    $('#links_direction').on("change", function () {
        $("#links_direction_outgoing").toggle()
        $("#links_direction_incoming").toggle()
    })

    // Clicking a KPI card opens (and scrolls to) its problem section.
    $(document).on('click', '.cl-stat-card--clickable', function () {
        const spoiler = $(this).data('spoiler');
        if (!spoiler) return;
        $('#' + spoiler).prop('checked', true);
        const label = document.getElementById('label_' + spoiler);
        if (label) label.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    // Orphan-fix: fetch donor posts + suggested anchors for one orphan on demand.
    $(document).on('click', '.cl-find-donors', function () {
        const $btn = $(this);
        const postId = $btn.data('postid');
        const $row = $btn.closest('tr');

        // Toggle: if the panel is already open, close it.
        const $existing = $row.next('.cl-donor-row');
        if ($existing.length) {
            $existing.remove();
            $btn.text('Найти доноров');
            return;
        }

        $btn.text('Загрузка...').prop('disabled', true);
        $.ajax({
            type: "POST",
            url: ajaxurl,
            dataType: 'json',
            data: { action: 'linkate_orphan_donors', _ajax_nonce: cherry_ajax.nonce, post_id: postId },
            success: function (resp) {
                $btn.text('Скрыть доноров').prop('disabled', false);
                $row.after(render_donor_row(resp && resp.donors));
            },
            error: function () {
                $btn.text('Найти доноров').prop('disabled', false);
                $row.after('<tr class="cl-donor-row"><td colspan="3"><div class="cl-donors cl-donors-error">Не удалось получить рекомендации.</div></td></tr>');
            }
        });
    });

    // Get posts count to know how many requests we have to make
    function stats_get_posts_count() {
        let ajax_data = stats_serialized_form + '&action=linkate_get_all_posts_count&_ajax_nonce=' + cherry_ajax.nonce;

        $.ajax({
            type: "POST",
            url: ajaxurl,
            data: ajax_data,
            datatype: 'text',
            success: function (response) {
                // response = JSON.parse(response);
                console.log("Starting process with " + response + " posts found");
                stats_posts_count = parseInt(response);
                // update stats_posts_count
                stats_interval_check = setInterval(stats_process_next, 500);
            },
            error: function (jqXHR, textStatus, errorThrown ) {
                handle_errors(errorThrown, jqXHR.responseText);
            }
        });
    }

    // Process next batch of posts
    function stats_process_next() {
        if (stats_offset >= stats_posts_count) {
            console.timeEnd('overall_time');
            clearInterval(stats_interval_check);

            $('#csv_progress').hide();
            $('#link_check_progress').hide();
            $("input").prop('disabled', false);
            console.log("Stats created successfully")
            if (!stats_admin_preview) {
                stats_get_file();
            } else {
                console.log("Resulting object")
                console.log(stats_object)
                create_preview_html();
            }
            return;
        }

        if (in_progress)
            return;

        let direction = $('#links_direction option:selected').val();
        let ajax_action = '';
        if (direction == "incoming")
            ajax_action = "linkate_generate_csv_or_json_prettyfied_backwards";
        else
            ajax_action = "linkate_generate_csv_or_json_prettyfied";

        let ajax_data = stats_serialized_form
            + '&action=' + ajax_action
            + '&stats_offset=' + stats_offset
            + '&stats_limit=' + stats_limit
            + '&_ajax_nonce=' + cherry_ajax.nonce;

        in_progress = true;
        $.ajax({
            type: "POST",
            url: ajaxurl,
            data: ajax_data,
            datatype: 'json',
            success: function (response) {
                console.log(JSON.parse(response));
                stats_object = Object.assign(stats_object, JSON.parse(response));
                stats_offset += stats_limit;
                in_progress = false;
                stats_update_progress();
            },
            error: function (jqXHR, textStatus, errorThrown ) {
                handle_errors(errorThrown, jqXHR.responseText);
            }
        });
    }

    // Display CSV creation progress
    function stats_update_progress() {
        let current = 0;
        current = Math.round(stats_offset / stats_posts_count * 100)
        if (process_type === 'preview') {

            $('#link_check_progress').prop('max', 100);
            $('#link_check_progress').val(current);
        } else {
            $('#csv_progress').prop('max', 100);
            $('#csv_progress').val(current);
            
        }
    }

    // Create results file
    function stats_get_file() {
        let ajax_data = 'action=linkate_merge_csv_files&_ajax_nonce=' + cherry_ajax.nonce;

        $.ajax({
            type: "POST",
            url: ajaxurl,
            data: ajax_data,
            datatype: 'json',
            success: function (response) {
                response = JSON.parse(response);
                console.log(response);
                // $('#generate_csv').after('<a id="btn_csv_dload" class="button button-download" href="' + response['url'] + '" download>Скачать файл</a>');
                location.href= response['url'];
            },
            error: function (jqXHR, textStatus, errorThrown ) {
                handle_errors(errorThrown, jqXHR.responseText);
            }
        });
    }

    // Stats preview

    // One KPI tile. `spoilerId` (optional) makes it clickable → opens that spoiler.
    function stat_card(label, value, tone, spoilerId) {
        const clickable = spoilerId ? ' cl-stat-card--clickable' : '';
        const dataAttr  = spoilerId ? ' data-spoiler="' + spoilerId + '"' : '';
        return '<div class="cl-stat-card tone-' + tone + clickable + '"' + dataAttr + '>'
            + '<div class="cl-stat-value">' + value + '</div>'
            + '<div class="cl-stat-label">' + label + '</div>'
            + '</div>';
    }

    function create_preview_summary (posts_obj) {
        const no_inc  = posts_obj.no_incoming.length;
        const no_out  = posts_obj.no_outgoing.length;
        const has_rep = posts_obj.has_repeats.length;
        const has_404 = posts_obj.has_404.length;
        const has_rec = posts_obj.has_recursion.length;
        const links_count = parseInt($("#cherry_preview_stats_summary").attr('data-linkscount')) || 0;

        let cards = '';
        cards += stat_card('Проверено записей', stats_posts_count, 'neutral', null);
        cards += stat_card('Всего ссылок',      links_count,       'neutral', null);
        cards += stat_card('Без входящих',  no_inc,  no_inc  > 0 ? 'danger' : 'ok', no_inc  > 0 ? 'spoiler_no_incoming'   : null);
        cards += stat_card('Без исходящих', no_out,  no_out  > 0 ? 'warn'   : 'ok', no_out  > 0 ? 'spoiler_no_outgoing'   : null);
        cards += stat_card('Повторы ссылок', has_rep, has_rep > 0 ? 'warn'  : 'ok', has_rep > 0 ? 'spoiler_has_repeats'    : null);
        cards += stat_card('Битые ссылки',  has_404, has_404 > 0 ? 'danger' : 'ok', has_404 > 0 ? 'spoiler_has_404'       : null);
        cards += stat_card('Ссылки на себя', has_rec, has_rec > 0 ? 'warn'  : 'ok', has_rec > 0 ? 'spoiler_has_recursion'  : null);

        let output = '<div class="cl-stat-cards">' + cards + '</div>';
        if (!(no_inc || no_out || has_rep || has_404 || has_rec)) {
            output += (links_count === 0)
                ? '<p class="mt-3">Перелинковка не обнаружена.</p>'
                : '<p class="mt-3">Проблем с текущей перелинковкой не найдено.</p>';
        }
        $("#cherry_preview_stats_summary").html(output);
    }

    // Shared cell builders — every problem table uses the real post id + title now,
    // not the raw permalink (which used to fill both the "Post ID" and "URL" columns).
    function cl_id_cell (v)    { return '<td>' + (v.post_id || '') + '</td>'; }
    function cl_title_cell (v) {
        const t = v.title || v.url;
        return '<td><a href="' + v.url + '" target="_blank">' + t + '</a>'
            + '<div class="cl-row-url">' + v.url + '</div></td>';
    }
    function cl_edit_link (v) {
        return '<a href="/wp-admin/post.php?post=' + v.post_id + '&action=edit" target="_blank" class="button button-small">В редактор</a>';
    }

    // Orphan-fix: render the donor suggestions returned for one orphan into a sub-row.
    function render_donor_row (donors) {
        if (!donors || !donors.length) {
            return '<tr class="cl-donor-row"><td colspan="3"><div class="cl-donors">Подходящих доноров не найдено.</div></td></tr>';
        }
        const items = donors.map(function (d) {
            const anchor = d.anchor
                ? 'якорь: «<strong>' + d.anchor + '</strong>»'
                : '<em>подходящий анкор не найден</em>';
            return '<li class="cl-donor-item">'
                + '<div class="cl-donor-main">'
                +   '<a href="' + d.source_url + '" target="_blank" class="cl-donor-title">' + d.source_title + '</a>'
                +   '<div class="cl-donor-anchor">' + anchor + '</div>'
                + '</div>'
                + '<a href="' + d.edit_url + '" target="_blank" class="button button-small">Открыть донор</a>'
                + '</li>';
        }).join('');
        return '<tr class="cl-donor-row"><td colspan="3">'
            + '<div class="cl-donors">'
            +   '<div class="cl-donors-title">Откуда можно сослаться на эту статью:</div>'
            +   '<ul class="cl-donor-list">' + items + '</ul>'
            + '</div></td></tr>';
    }

    function create_preview_html () {
        let output = "";
        let posts = {
            no_incoming: [],
            no_outgoing: [],
            has_repeats: [],
            has_recursion: [],
            has_404: []
        }

        for (const key in stats_object) {
            if (stats_object.hasOwnProperty(key)) {
                const element = stats_object[key];
                if (element.has_repeats) {
                    posts.has_repeats.push(Object.assign({id: key}, element))
                }
                if (!element.has_outgoing) {
                    posts.no_outgoing.push(Object.assign({id: key}, element))
                }
                if (!element.has_incoming) {
                    posts.no_incoming.push(Object.assign({id: key}, element))
                }
                if (Object.values(element.err_404).length > 0) {
                    posts.has_404.push(Object.assign({id: key}, element))
                }
                if (element.recursion.length > 0) {
                    posts.has_recursion.push(Object.assign({id: key}, element))
                }
            }
        }

        create_preview_summary(posts);

        let table_limit = 150;
        let open_spoiler_id = false;
        let out_repeats = '';
        if (posts.has_repeats.length > 0) {
            out_repeats = posts.has_repeats.map((v, k) => {
                let repeats = '';
                for (const target in v.targets) {
                    if (v.targets.hasOwnProperty(target)) {
                        const count = v.targets[target];
                        repeats += "<li><a href=\""+target+"\" target=\"_blank\">" + target + "</a> <strong>("+count+")</strong>";
                    }
                }
                repeats = "<ol>" + repeats + "</ol>";
                return `<tr>${cl_id_cell(v)}${cl_title_cell(v)}<td>${repeats}</td><td>${cl_edit_link(v)}</td></tr>`;
            }).join('\n');
            $("#label_spoiler_has_repeats").html("Найдены повторы ("+posts.has_repeats.length+")");
            $("div.spoiler_has_repeats").html("<table class='cherry-stats-preview-table'><thead><tr><th>ID</th><th>Запись</th><th>Ссылается на (количество)</th><th>Действия</th></tr></thead><tbody>" + out_repeats + "</tbody></table>")
            if (!open_spoiler_id) open_spoiler_id = "#label_spoiler_has_repeats";
        } else {
            $("#label_spoiler_has_repeats").hide();
            $("div.spoiler_has_repeats").hide();
        }
        
        let out_incoming = '';
        if (posts.no_incoming.length > 0) {
            out_incoming = posts.no_incoming.slice(0,table_limit).map((v, k) => {
                return `<tr class="cl-orphan-row">${cl_id_cell(v)}${cl_title_cell(v)}<td class="cl-orphan-actions"><button type="button" class="cl-find-donors button button-small" data-postid="${v.post_id}">Найти доноров</button>${cl_edit_link(v)}</td></tr>`;
            }).join('\n');
            $("#label_spoiler_no_incoming").html("Статьи без входящих ссылок ("+posts.no_incoming.length+")");
            out_incoming = "<table class='cherry-stats-preview-table'><thead><tr><th>ID</th><th>Запись</th><th>Действия</th></tr></thead><tbody>" + out_incoming + "</tbody></table>";
            if (posts.no_incoming.length > table_limit) {
                out_incoming += "<p>Показано только 50 первых результатов.</p>";
            }
            $("div.spoiler_no_incoming").html(out_incoming);
            open_spoiler_id = "#label_spoiler_no_incoming";
        } else {
            $("#label_spoiler_no_incoming").hide();
            $("div.spoiler_no_incoming").html("Повторы ссылок не обнаружены")
        }
        let out_outgoing = '';
        if (posts.no_outgoing.length > 0) {
            out_outgoing = posts.no_outgoing.slice(0,table_limit).map((v, k) => {
                return `<tr>${cl_id_cell(v)}${cl_title_cell(v)}<td>${cl_edit_link(v)}</td></tr>`;
            }).join('\n');
            $("#label_spoiler_no_outgoing").html("Статьи, которые никуда не ссылаются ("+posts.no_outgoing.length+")");
            out_outgoing = "<table class='cherry-stats-preview-table'><thead><tr><th>ID</th><th>Запись</th><th>Действия</th></tr></thead><tbody>" + out_outgoing + "</tbody></table>";
            if (posts.no_outgoing.length > table_limit) {
                out_outgoing += "<p>Показано только 50 первых результатов.</p>";
            }
            $("div.spoiler_no_outgoing").html(out_outgoing);
            if (!open_spoiler_id) open_spoiler_id = "#label_spoiler_no_outgoing";
        } else {
            $("#label_spoiler_no_outgoing").hide();
            $("div.spoiler_no_outgoing").hide();
        }
        let out_404 = '';
        if (posts.has_404.length > 0) {
            out_404 = posts.has_404.slice(0,table_limit).map((v, k) => {
                let bad_urls = '';
                for (const bu in v.err_404) {
                    if (v.err_404.hasOwnProperty(bu)) {
                        const ankor = v.err_404[bu];
                        bad_urls += "<li><a href=\""+bu+"\" target=\"_blank\">" + bu + "</a> <strong>("+ankor+")</strong>";
                    }
                }
                bad_urls = "<ol>" + bad_urls + "</ol>";
                return `<tr>${cl_id_cell(v)}${cl_title_cell(v)}<td>${bad_urls}</td><td>${cl_edit_link(v)}</td></tr>`;
            }).join('\n');
            $("#label_spoiler_has_404").html("Статьи cо сломанными ссылками ("+posts.has_404.length+")");
            out_404 = "<table class='cherry-stats-preview-table'><thead><tr><th>ID</th><th>Запись</th><th>Нерабочие ссылки (анкор)</th><th>Действия</th></tr></thead><tbody>" + out_404 + "</tbody></table>";
            if (posts.has_404.length > table_limit) {
                out_404 += "<p>Показано только 50 первых результатов.</p>";
            }
            $("div.spoiler_has_404").html(out_404);
            if (!open_spoiler_id) open_spoiler_id = "#label_spoiler_has_404";
        } else {
            $("#label_spoiler_has_404").hide();
            $("div.spoiler_has_404").hide();
        }

        let out_recursion = '';
        if (posts.has_recursion.length > 0) {
            out_recursion = posts.has_recursion.slice(0,table_limit).map((v, k) => {
                let bad_urls = '';
                for (const ankor of v.recursion) {
                        bad_urls += "<li><strong>"+ankor+"</strong>";
                }
                bad_urls = "<ol>" + bad_urls + "</ol>";
                return `<tr>${cl_id_cell(v)}${cl_title_cell(v)}<td>${bad_urls}</td><td>${cl_edit_link(v)}</td></tr>`;
            }).join('\n');
            $("#label_spoiler_has_recursion").html("Статьи, которые ссылаются на себя ("+posts.has_recursion.length+")");
            out_recursion = "<table class='cherry-stats-preview-table'><thead><tr><th>ID</th><th>Запись</th><th>Анкор</th><th>Действия</th></tr></thead><tbody>" + out_recursion + "</tbody></table>";
            if (posts.has_recursion.length > table_limit) {
                out_recursion += "<p>Показано только 50 первых результатов.</p>";
            }
            $("div.spoiler_has_recursion").html(out_recursion);
            if (!open_spoiler_id) open_spoiler_id = "#label_spoiler_has_recursion";
        } else {
            $("#label_spoiler_has_recursion").hide();
            $("div.spoiler_has_recursion").hide();
        }

        $("#form_generate_stats").hide();
        $("#cherry_preview_stats_container").show();
        $(open_spoiler_id).trigger("click");
    }

    function handle_errors (error_msg, error_details) {
        console.log(error_msg);
        $('#csv_progress').hide();
        $('#link_check_progress').hide();
        $("input").prop('disabled', false);
        if (process_type === 'preview') {
            $('#link_check_progress').parent().append("<p>Что-то пошло не так, возникла ошибка при обработке запроса: <strong>" + error_msg +"</strong>.</p>" + error_details);
        } else {
            $('#csv_progress').parent().append("<p>Что-то пошло не так, возникла ошибка при обработке запроса: <strong>" + error_msg +"</strong>.</p>" + error_details);
        }
    }
});