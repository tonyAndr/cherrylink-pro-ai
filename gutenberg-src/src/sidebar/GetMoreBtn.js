import React, { Component } from 'react'
import { Spinner } from '@wordpress/components'

export class GetMoreBtn extends Component {

    constructor(props) {
        super(props);
        this.requestUpdate = this.requestUpdate.bind(this);
    }

    requestUpdate() {
        this.props.requestUpdate()
    }

    render() {
        const { inProgress, dataStatus } = this.props;

        if (inProgress) {
            return (<div className="terms-tab-spinner"><Spinner /></div>)
        } else if (dataStatus !== undefined && dataStatus <= 1) {
            return (
                <div className="get-data-button">
                    <button onClick={this.requestUpdate}>Загрузить еще</button>
                </div>
            )
        }

        return null;
    }
}
