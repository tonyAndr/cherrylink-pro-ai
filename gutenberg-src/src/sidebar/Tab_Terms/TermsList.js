import React from 'react';
import './Terms.css';
import TermItem from './TermItem';
import { Spinner } from '@wordpress/components';

class TermsList extends React.Component {
    constructor(props) {
        super(props);

    }

    isInArticle(item) {
        const { convertUrl, stats } = this.props;
        let inAricle = false;
        const convertedUrl = convertUrl(item.url);
        for (let i in stats) {
            if (convertedUrl === stats[i].url) {
                inAricle = true;
                break;
            }
        }
        return inAricle;
    }

    createTermsList(termsList) {
        let listToRender = [];

        for (let i = 0; i < termsList.length; i++) {
            if (termsList[i].is_divider === "no") {
                const inArticle = this.isInArticle(termsList[i]);
                listToRender.push(this.convertToJSX(termsList[i], false, inArticle));
            } else {
                listToRender.push(<div key={'divider_'+i} className="term-divider">{termsList[i].name} на сайте</div>);
            }
        }

        return listToRender;
    }

    convertToJSX(term, isChild, inArticle) {

        let children = [];
        if (term.children.length > 0) {
            for (let i = 0; i < term.children.length; i++) {
                const _inArticle = this.isInArticle(term.children[i]);
                children.push(this.convertToJSX(term.children[i], true, _inArticle));
            }
        }
        let jsxTerm = <TermItem key={term.taxonomy + term.post_count + term.name} termName={term.name} termUrl={term.url} children={children} isChild={isChild} inArticle={inArticle} />;

        return jsxTerm;
    }

    render() {
        const { terms } = this.props;
        const jsxTerms = this.createTermsList(terms);

        let output;
        if (terms.inProgress) {
            output = <div className='terms-tab-spinner'><Spinner /></div>;
        } else if (terms.isError) {
            output = <p>Произошла ошибка во время загрузки...</p>;
        } else if (terms.length === 0 && jsxTerms.length === 0) {
            output = <p className='error-text'>Рубрики не найдены</p>;
        } else {
            output = jsxTerms;
        }

        return (
            <> 
                {output}
            </>
        )

    }

}

export default TermsList;