import React from 'react';

export class GetIncomingLinks extends React.Component {
    constructor(props) {
        super(props);
        // this.getLinkStats = this.getLinkStats.bind(this);
    }

    componentDidMount() {
        this.getLinkStats();
    }

    getLinkStats() {

        // get stats
        const url = ajax_var.url + '?action=linkate_generate_json'; 
        var payload = { 
            'this_id': wp.data.select("core/editor").getCurrentPostId(),
            'this_type': 'post'
        }
            
        fetch(url, {
            method: 'POST', // *GET, POST, PUT, DELETE, etc.
            body: JSON.stringify(payload) // body data type must match "Content-Type" header
        })
        .then(response => response.json())
        .then(response => {
            this.props.onChange(response)
        })
        .catch(error => console.error(error))



    }

    render() {
        return false;
    }
}
