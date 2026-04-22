import React from 'react';

export class GetAllTerms extends React.Component {

    constructor(props)  {
        super(props);
        this.getTerms = this.getTerms.bind(this);
    }


    getTerms() {
        const handleUpdate = this.props.onTermsChange;
        const url = ajax_var.url + '?action=linkate_gutenberg_hierarchical_terms_json'; // &post_id='+this_id+'&offset=0&is_term='+is_term

        handleUpdate({inProgress: true});

        fetch(url, {
            method: 'GET'
        })
        .then(response => response.json())
        .then(response => {
            // console.log(response);
            handleUpdate(response);
        })
        .catch(error => {
            handleUpdate({isError: true})
            console.error(error)
        })
    }

    componentDidMount() {
        if (this.props.currentTerms.length === 0)
            this.getTerms();
    }

    render () {
        return false;
    }
}