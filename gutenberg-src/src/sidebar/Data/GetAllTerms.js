import React from 'react';

export class GetAllTerms extends React.Component {
    constructor(props) {
        super(props);
        this.getTerms = this.getTerms.bind(this);
    }

    async getTerms() {
        const handleUpdate = this.props.onTermsChange;
        const url = ajax_var.url + '?action=linkate_gutenberg_hierarchical_terms_json&_ajax_nonce=' + ajax_var.nonce;

        handleUpdate({ inProgress: true });

        try {
            const response = await fetch(url, { method: 'GET' });
            const data = await response.json();
            handleUpdate(data);
        } catch (error) {
            handleUpdate({ isError: true });
            console.error(error);
        }
    }

    componentDidMount() {
        if (this.props.currentTerms.length === 0)
            this.getTerms();
    }

    render() {
        return false;
    }
}
