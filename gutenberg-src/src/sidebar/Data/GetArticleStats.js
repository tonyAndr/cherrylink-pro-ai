import React from 'react';

export class GetArticleStats extends React.Component {
    
    // WATCH POST DATA UPDATE

    componentDidMount() {

        let postContent = wp.data.select('core/editor').getEditedPostAttribute('content');
        this.collectStatistics(postContent);

        wp.data.subscribe(() => {
            const updatedContent = wp.data.select('core/editor').getEditedPostAttribute('content');

            if (postContent !== updatedContent) {
                postContent = updatedContent;
                this.collectStatistics(updatedContent);
            }
        })
    }

    collectStatistics(content) {
        const foundLinks = this.fcl_collectAllLinksFromContent(content);
        this.props.onChange(foundLinks);
    }

    // Collect all hrefs using regex
    fcl_collectAllLinksFromContent(text) {
        let reg = /<a.+?href=['"](.*?)['"].*?>(.*?)<\/a>/gi;
        let match;
        let matches = [];

        while (match = reg.exec(text)) {
            match[1] = this.props.convertUrl(match[1]);
            if (!this.fcl_fileTypeChecker(match[1])) {
                matches.push({
                    url: match[1],
                    keyword: this.decodeHtml(match[2]),
                    offset: this.countSimilar(matches, match[1]),
                });
            }
        }

        return matches;
    }

    countSimilar(array, url) {
        if (array.length > 0) {
            let count = 0;
            array.forEach(x => {
                if (x.url === url) {
                    count++;
                }
            })
            return count;
        }
        return 0;
    }

    decodeHtml(html) {
        const txt = document.createElement("textarea");
        txt.innerHTML = html;
        return txt.value;
    }

    fcl_fileTypeChecker(url) { // cuz we don't want to count media as int/ext links
        if (url === undefined)
            return true;
        let prohibited = ['.jpg', '.jpeg', '.tiff', '.bmp', '.psd', '.png', '.gif', '.webp', '.doc', '.docx', '.xlsx', '.xls', '.odt', '.pdf', '.ods', '.odf', '.ppt', '.pptx', '.txt', '.rtf', '.mp3', '.mp4', '.wav', '.avi', '.ogg', '.zip', '.7z', '.tar', '.gz', '.rar'];

        for (let i = prohibited.length - 1; i >= 0; i--) {
            if (url.indexOf(prohibited[i]) != -1) {
                return true;
            }
        }        
        // omit some navigational links, which starts with #
        if (url.indexOf('#') === 0) {
            return true;
        }
        return false;
    }

    // NOT RENDER

    render() {
        return false;
    }

}

