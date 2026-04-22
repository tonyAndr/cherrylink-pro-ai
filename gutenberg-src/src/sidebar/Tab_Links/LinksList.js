import React from 'react';
import './LinksToolbar.css';
import LinkItem from './LinkItem';
import withPostMeta from '../HOC/withPostMeta';
import Snowball from 'snowball';

class LinksList extends React.Component {
    constructor(props) {
        super(props);

        this.state = {
            blockLinks: [],
            manualEdit: false,
            showAfter: false,
            showAfterChanged: false
        };

        this.updateMetaState = this.updateMetaState.bind(this);
        this.textToSetOfStemms = this.textToSetOfStemms.bind(this);
        this.improvedFilterContains = this.improvedFilterContains.bind(this);
        this.stemmKeywords = this.stemmKeywords.bind(this);
    }

    isInArticle(item, stats) {
        let inAricle = false;
        const convertedUrl = this.props.convertUrl(item['data-url']);
        for (let i in stats) {
            if (convertedUrl === stats[i].url) {
                inAricle = true;
                break;
            }
        }
        return inAricle;
    }

    shouldBeShown(item, filters, inAricle) {
        // const currData = this.props.currentData;
        
        let hasText, hasCategory, showToggles;


        // if (!filters.textFilter || this.improvedFilterContains(item, filters.textFilter)) {
        //     hasText = true
        // } else {
        //     hasText = false;
        // }

        if ((cherrylink_options['show_cat_filter'] === 'true' || cherrylink_options['show_cat_filter'] === true)) {

            const itemCats = item['data-category'].split(",");
            const intersection = itemCats.filter(x => filters.checkedCategories.includes(x)).length > 0;
            if (filters.checkedCategories.length === 0 || intersection) {
                hasCategory = true;
            } else {
                hasCategory = false;
            }
        } else {
            hasCategory = true;
        }

        if ((inAricle && filters.showUsed) || (!inAricle && filters.showNotUsed)) {
            showToggles = true;
        } else {
            showToggles = false;
        }

        // return hasText && hasCategory && showToggles;
        return hasCategory && showToggles;

    }


    // Use title, seotitle, multiple words and stemming
    improvedFilterContains(item, filter) {
        // combine all possible words from the link (titles + suggestion?)
        let h1 = item["data-title"]
        let seo = item["data-titleseo"]
        let sugg = item["data-suggestions"]

        let combine = h1;
        if (seo) combine += ' ' + seo;
        if (sugg) combine += ' ' + sugg;

        let combinedStemms = this.textToSetOfStemms(combine);
        let filterStemms = this.textToSetOfStemms(filter);

        let contains = true;
        filterStemms.forEach(stemm => {
            if (!combinedStemms.includes(stemm) && combinedStemms.join(' ').indexOf(stemm) === -1) {
                contains = false;
            }
        });
        return contains;
    }

    textToSetOfStemms(text) {
        if (!text) return [];
        text = text.replaceAll(/[^A-ZА-Я0-9\s]/ig, '').replaceAll(/\s+/g, ' ').toLowerCase().trim().split(/\s/);
        text = [...new Set(text)]; // removes duplicates
        for (let i = 0; i < text.length; i++) {
            const word = text[i];
            text[i] = this.stemmKeywords(word);
        }
        text = [...new Set(text)]; // removes duplicates again
        return text;
    }

    stemmKeywords(text) {
        let words = text.trim().split(" ");
        const stemmer = new Snowball('Russian');
        words = words.map(x => {
            stemmer.setCurrent(x);
            stemmer.stem();
            return stemmer.getCurrent();
        })

        return words;
    }
    
    updateMetaState(newState) {
        if (newState !== undefined) {
            this.props.updateMeta(newState);
            this.setState(prevState => (newState));
        } else {
            this.setState(prevState => {
                    const meta = this.props.getMeta()
                    return meta;
                }
                
            );
        }
    }

    componentDidMount() {
        this.updateMetaState();
    }

    componentDidUpdate(prevProps) {
        if (this.props.scrollPos) {
            let element = document.querySelector(".editor-sidebar")
            if (element === null) {
                element = document.querySelector(".edit-post-sidebar")
            }
            element.scrollTop = this.props.scrollPos;
            this.props.updateScrollPos(0);
        }
    }

    render () {
        const { filters, data, stats, showSuggestions, convertUrl, dataStatus, incomingLinks } = this.props;
        
        if (dataStatus === 3) {
            return (
                <p className='links-not-found'>Ничего не удалось найти. Попробуйте изменить настройки <a target="_blank" href={window.location.origin + "/wp-admin/options-general.php?page=cherrylink-pro"}>фильтрации или релевантности</a>.</p>
            )
        }

        const editUrl = `${window.location.origin}/wp-admin/post.php`;
        let links = [];
        if (data instanceof Array && data.length > 0) {
            let incomingReduced = [];
            try {
                incomingReduced = incomingLinks.links.map(x => {
                    return x['source_id'];
                }) 
            } catch (err) {
                incomingReduced = [];
            }
            links = data.map((x, index) => {
                // добавить фильтрацию
                const inAricle = this.isInArticle(x, stats);
                const isVisible = this.shouldBeShown(x, filters, inAricle);
                const isIncoming = incomingReduced.includes(x["data-postid"]);
                if (isVisible)
                    return (
                        <LinkItem key={'link_'+index} editUrl={editUrl} linkObj={x} showSuggestions={showSuggestions} inArticle={inAricle} isIncoming={isIncoming} postMeta={this.state} convertUrl={convertUrl} onMetaChange={this.updateMetaState}/>
                    )
            });
        }

        return (
            <div>
                {links}
            </div>
        )
        
    }

}

export default withPostMeta(LinksList);