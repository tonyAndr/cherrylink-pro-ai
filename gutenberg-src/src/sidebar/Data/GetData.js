import React from 'react';
import { showNotice } from '../Notices';

export class GetData extends React.Component {
    constructor(props) {
        super(props);
        this.loadData = this.loadData.bind(this);
    }

    componentDidMount() {
        if (this.props.currentData.updateRequested)
            this.loadData();
    }

    componentDidUpdate(prevProps) {
        if (this.props.currentData.updateRequested)
            this.loadData(prevProps.currentFilters.textFilter !== this.props.currentFilters.textFilter);
    }

    async loadData(withFilter = false) {
        const { currentData, currentFilters, onDataChange: handleDataUpdate } = this.props;
        const this_id = cherrylink_options['post_id'].length == 0 || cherrylink_options['post_id'] == 0
            ? window.location.href.match(/tag_ID=(\d+)\&/i)[1]
            : cherrylink_options['post_id'];
        const is_term = window.location.href.indexOf('term.php') > -1 ? 1 : 0;
        const url = ajax_var.url + '?action=get_linkate_links';
        const payload = {
            post_id: this_id,
            offset: withFilter ? 0 : currentData.dataOffset,
            is_term,
            custom_text: currentFilters.textFilter
        };
        const newData = {
            data: withFilter ? [] : currentData.data,
            dataOffset: withFilter ? 0 : currentData.dataOffset,
            loadLimit: currentData.loadLimit,
            dataStatus: currentData.dataStatus,
            inProgress: true,
            updateRequested: false
        };

        handleDataUpdate(newData);

        try {
            const response = await fetch(url, { method: 'POST', body: JSON.stringify(payload) });
            const data = await response.json();

            if (data.count > -1) {
                if (withFilter) {
                    newData.data = JSON.parse(data.links);
                    newData.dataOffset = data.count;
                } else {
                    newData.data = newData.data.concat(JSON.parse(data.links));
                    newData.dataOffset = newData.dataOffset + data.count;
                }
            } else {
                showNotice('Ничего не нашлось');
                newData.data = [];
                newData.dataOffset = 0;
                newData.inProgress = false;
            }

            if (data.count > 0 && data.count === newData.loadLimit) {
                newData.dataStatus = 1;
            } else if (data.count === -1) {
                newData.dataStatus = 3;
            } else if (data.count > 0 && data.count < newData.loadLimit) {
                newData.dataStatus = 2;
            }

            if (newData.data.length > 0) {
                await this.getLinkStats(newData);
            } else {
                newData.inProgress = false;
            }

            handleDataUpdate(newData);
        } catch (error) {
            console.error(error);
            showNotice('Произошла ошибка при загрузке (подробности в консоли)');
            newData.inProgress = false;
            handleDataUpdate(newData);
        }
    }

    async getLinkStats(currentData) {
        const ids = currentData.data
            .filter(el => el['data-incoming'] === undefined)
            .map(el => el['data-postid']);

        if (ids.length === 0) {
            currentData.inProgress = false;
            return;
        }

        const url = ajax_var.url + '?action=linkate_generate_csv_or_json_prettyfied';
        const payload = {
            'from_editor': true,
            'post_ids': ids.join(",")
        };

        try {
            const response = await fetch(url, { method: 'POST', body: JSON.stringify(payload) });
            const data = await response.json();

            currentData.data = currentData.data.map(obj => {
                const key = `"id_${obj["data-postid"]}"`;
                return data[key] !== undefined
                    ? { ...obj, "data-incoming": data[key][1], "data-outgoing": data[key][0] }
                    : obj;
            });
            currentData.inProgress = false;
        } catch (error) {
            console.error(error);
            currentData.inProgress = false;
            showNotice('Произошла ошибка при загрузке (подробности в консоли)');
        }
    }

    render() {
        return null;
    }
}
