import React from 'react';
import { PanelRow, TextControl, Disabled, Tooltip, Button } from '@wordpress/components';
import withLinkInsert from '../HOC/withLinkInsert'

class BlockLinksList extends React.Component {
    constructor(props) {
        super(props);

        this.state = {
            timeoutId: false,
            blockLinks: false
        }
    }

    componentDidMount() {
        const { postMeta } = this.props;
        this.setState({
            blockLinks: postMeta.blockLinks
        });
    }

    componentDidUpdate(prevProps, prevState, snapshot) {
        if (prevState.blockLinks === false || prevProps.postMeta.blockLinks !== this.props.postMeta.blockLinks) {
            const { postMeta } = this.props;
            this.setState({
                blockLinks: postMeta.blockLinks
            });
        }
    }

    componentWillUnmount() {
        this.props.updateBlockLinks(this.state.blockLinks);
        clearTimeout(this.state.timeoutId);
    }

    onTyping(index, text) {
        let { blockLinks, timeoutId } = this.state;

        blockLinks[index].m_keyword = text;

        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => { this.props.updateBlockLinks(blockLinks); }, 500);

        this.setState({
            blockLinks: blockLinks,
            timeoutId: timeoutId
        });


    }

    render() {
        const { changeBlockLinks, postMeta, updateMetaState } = this.props;
        const { blockLinks } = this.state;


        let output;
        if (blockLinks !== false && blockLinks.length > 0) {
            const links = blockLinks.map((x, i) => (
                <div key={"blocklink_"+i}>
                    <PanelRow>
                        <Tooltip text={`Оригинальный анкор: ${x.keyword}`}>
                            <div className=''>ID: {x.post_id}</div>
                        </Tooltip>
                        <Button
                            isLink
                            isSmall
                            isDestructive
                            onClick={() => changeBlockLinks(x.post_id, x.keyword, postMeta, updateMetaState)}
                        ><strong>Удалить</strong></Button>
                    </PanelRow>
                    <div>
                        {postMeta.manualEdit ? <TextControl __next40pxDefaultSize value={x.m_keyword} onChange={(text) => this.onTyping(i, text)}></TextControl> : <Disabled><TextControl __next40pxDefaultSize value={x.keyword}></TextControl></Disabled>}
                    </div>
                </div>
            ))
            output = (
                <>
                    <div>{postMeta.manualEdit ? "Введите желаемые анкоры в поля ниже. Если анкор не введен будет использоваться заголовок статьи." : "Включите редактор анкоров для ручного ввода."}</div>
                    {links}
                </>
            )
        } else {
            output = (
                <>
                    Ссылки не выбраны. Перейдите на таб со ссылками и добавьте желаемые в этот список.
                </>
            )
        }

        return (
            <>
                <h2>Добавленные ссылки</h2>

                {output}
            </>
        )
    }
}

export default withLinkInsert(BlockLinksList);