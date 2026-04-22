import React from 'react';
import { CheckboxControl } from '@wordpress/components';

export class FilterTermItem extends React.Component {
    constructor(props)  {
        super(props)
        this.state = {checked: false};

        this.changeState = this.changeState.bind(this)
    }

    changeState(newState) {
        this.setState({checked: newState})
        this.props.onChange(this.props.catName, newState, this.props.parentName)
    }

    componentDidMount() {
        if (this.props.isChecked)
            this.setState({checked: true});
    }

    render () {
        const className = this.props.isChild ? 'term-checkbox-child' : 'term-checkbox-toplvl';
        return (
            <div className={className}>
                <CheckboxControl
                    label={this.props.catName}
                    checked={ this.state.checked }
                    onChange={ this.changeState }
                />
                {this.props.children.length > 0 && this.props.children}
            </div>
        )
    }
}