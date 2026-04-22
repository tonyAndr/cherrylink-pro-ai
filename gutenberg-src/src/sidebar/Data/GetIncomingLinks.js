import React from 'react';

export class GetIncomingLinks extends React.Component {
    componentDidMount() {
        this.getLinkStats();
    }

    async getLinkStats() {
        const url = ajax_var.url + '?action=linkate_generate_json';
        const payload = {
            'this_id': wp.data.select("core/editor").getCurrentPostId(),
            'this_type': 'post'
        };

        try {
            const response = await fetch(url, { method: 'POST', body: JSON.stringify(payload) });
            const data = await response.json();
            this.props.onChange(data);
        } catch (error) {
            console.error(error);
        }
    }

    render() {
        return false;
    }
}
