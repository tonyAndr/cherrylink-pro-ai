import React from 'react';
import './LinksToolbar.css';
import LinkItem from './LinkItem';

class LinksList extends React.Component {

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
        let hasCategory, showToggles;

        if ((cherrylink_options['show_cat_filter'] === 'true' || cherrylink_options['show_cat_filter'] === true)) {
            const itemCats = item['data-category'].split(",");
            const intersection = itemCats.filter(x => filters.checkedCategories.includes(x)).length > 0;
            hasCategory = filters.checkedCategories.length === 0 || intersection;
        } else {
            hasCategory = true;
        }

        showToggles = (inAricle && filters.showUsed) || (!inAricle && filters.showNotUsed);

        return hasCategory && showToggles;
    }

    componentDidUpdate(prevProps) {
        if (this.props.scrollPos) {
            let element = document.querySelector(".editor-sidebar");
            if (element === null) {
                element = document.querySelector(".edit-post-sidebar");
            }
            element.scrollTop = this.props.scrollPos;
            this.props.updateScrollPos(0);
        }
    }

    render() {
        const { filters, data, stats, showSuggestions, convertUrl, dataStatus, incomingLinks } = this.props;

        if (dataStatus === 3) {
            return (
                <p className='links-not-found'>Ничего не удалось найти. Попробуйте изменить настройки <a target="_blank" href={window.location.origin + "/wp-admin/options-general.php?page=cherrylink-pro"}>фильтрации или релевантности</a>.</p>
            );
        }

        const editUrl = `${window.location.origin}/wp-admin/post.php`;
        let links = [];
        if (data instanceof Array && data.length > 0) {
            let incomingReduced = [];
            try {
                incomingReduced = incomingLinks.links.map(x => x['source_id']);
            } catch (err) {
                incomingReduced = [];
            }
            links = data.map((x, index) => {
                const inAricle = this.isInArticle(x, stats);
                const isVisible = this.shouldBeShown(x, filters, inAricle);
                const isIncoming = incomingReduced.includes(x["data-postid"]);
                if (isVisible)
                    return (
                        <LinkItem key={'link_' + index} editUrl={editUrl} linkObj={x} showSuggestions={showSuggestions} inArticle={inAricle} isIncoming={isIncoming} convertUrl={convertUrl} />
                    );
            });
        }

        return (
            <div>
                {links}
            </div>
        );
    }
}

export default LinksList;
