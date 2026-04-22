import React from 'react';
import { FilterTermItem } from './FilterTermItem';

export class FilterTermsList extends React.Component {

    constructor(props)  {
        super(props);
        this.createTermsList = this.createTermsList.bind(this);
        this.updateCheckedTerms = this.updateCheckedTerms.bind(this);
    }
    

    createTermsList() {
        const { checkedCategories } = this.props;
        const termsList = this.props.terms;
        let listToRender = [];
        for (let i = 0; i < termsList.length; i++) {
            if (termsList[i].is_divider === "no" && termsList[i].taxonomy === "category")
                listToRender.push(this.convertToJSX(termsList[i], false, checkedCategories));
        }

        return listToRender;
    }

    updateCheckedTerms(termName, isChecked) {
        const { checkedCategories } = this.props;
        let updatedCategories = [];
        if (isChecked) {
            if (!checkedCategories.includes(termName)) {
                updatedCategories = checkedCategories.slice();
                updatedCategories.push(termName);
            }
        } else {
            if (checkedCategories.includes(termName)){
                updatedCategories = checkedCategories.filter(item => item !== termName)
            }
        }
        
        this.props.onChange({checkedCategories: updatedCategories});

    }

    convertToJSX(term, isChild, checkedCategories, parentName) {
        parentName = parentName || "";
        
        let children = [];
        if (term.children.length > 0) {
            for (let i = 0; i < term.children.length; i++) {
                children.push(this.convertToJSX(term.children[i], true, checkedCategories, term.name));
            }
        } 
        const isChecked = checkedCategories.includes(term.name) ? true : false;
        let jsxTerm = <FilterTermItem key={term.name+parentName} catName={term.name} parentName={parentName} children={children} isChild={isChild} isChecked={isChecked} onChange={this.updateCheckedTerms}/>;

        return jsxTerm;
    }

    // componentDidMount() {
    //     const currentState = Object.assign({}, this.state);
    //     currentState.terms = this.props.terms;
    //     currentState.checkedTerms = this.props.currentFilters.subCategory;
    //     this.setState(currentState)
    // }

    render () {
        const termsToRender = this.createTermsList();
        return (
                <div>
                    <div className="filter-terms-container">
                        {termsToRender}
                    </div>
                </div>
            );
    }
}