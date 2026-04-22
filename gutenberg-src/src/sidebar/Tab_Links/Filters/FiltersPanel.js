import React from 'react';
import { TextFilter } from './TextFilter';
import { FilterTermsList } from './FilterTermsList';
import { ToggleControl } from '@wordpress/components';
import './FiltersPanel.css';

import {Panel, PanelBody, PanelRow} from '@wordpress/components';

export class FiltersPanel extends React.Component {
    constructor(props) {
        super(props);

        this.updateFilters = this.updateFilters.bind(this);
        this.updateTextFilter = this.updateTextFilter.bind(this);
        this.updateShowUsed = this.updateShowUsed.bind(this);
        this.updateShowNotUsed = this.updateShowNotUsed.bind(this);
    }

    updateFilters(updatedFilters) {
        const {textFilter, showUsed, showNotUsed, checkedCategories} = updatedFilters;

        let updateNeeded = false;

        const propsFilters = Object.assign({}, this.props.currentFilters);
        
        if (textFilter !== undefined && textFilter !== propsFilters.textFilter) {
            propsFilters.textFilter = textFilter;
            updateNeeded = true;
        }

        if (checkedCategories !== undefined && checkedCategories !== propsFilters.subCategory) {
            propsFilters.checkedCategories = checkedCategories;
            updateNeeded = true;
        }

        if (showUsed !== undefined && showUsed !== propsFilters.showUsed) {
            propsFilters.showUsed = showUsed;
            updateNeeded = true;
        }

        if (showNotUsed !== undefined && showNotUsed !== propsFilters.showNotUsed) {
            propsFilters.showNotUsed = showNotUsed;
            updateNeeded = true;
        }

        if (updateNeeded)
            this.props.handleFiltersUpdate(propsFilters);
    
    }

    updateTextFilter(updatedText) {

        let updateNeeded = false;

        const propsFilters = Object.assign({}, this.props.currentFilters);
        
        if (updatedText !== undefined && updatedText !== propsFilters.textFilter) {
            propsFilters.textFilter = updatedText;
            updateNeeded = true;
        }

        if (updateNeeded)
            this.props.handleTextFiltersUpdate(propsFilters);
    
    }

    updateShowUsed(isChecked) {
        this.updateFilters({showUsed: isChecked})
    }

    updateShowNotUsed(isChecked) {
        this.updateFilters({showNotUsed: isChecked})
    }


    // в пропс имеем текст и список ссылок
    // из детей получаем фильтры
    // определить вердикт по фильтрам для каждой ссылки
    // заслать список ссылок обратно

    render () {
        const { currentFilters, currentTerms } = this.props;
        return (
        <>
            <TextFilter restoredText={currentFilters.textFilter} onChange={this.updateTextFilter} />
            {(cherrylink_options['show_cat_filter'] === 'true' || cherrylink_options['show_cat_filter'] === true) && (

                <Panel className='cherry-filter-panel'>
                    <PanelBody title="По рубрике"  initialOpen={ false }>
                        <PanelRow>
                                <div>
                                    {/* <div className="subheader">Наличие в тексте</div>
                                    <div className="cherry-filters-toggles">
                                        <div><ToggleControl
                                        label = "Есть" 
                                        checked = { currentFilters.showUsed }
                                        onChange = { this.updateShowUsed } /></div>
                                        <div><ToggleControl
                                        label = "Нет" 
                                        checked = { currentFilters.showNotUsed }
                                        onChange = { this.updateShowNotUsed } /></div>
                                    </div> */}
                                    
                                    {/* <div className="subheader">По слову в заголовке</div> */}
                                    
                                    {/* <div className="subheader">По категории</div> */}
                                    <FilterTermsList terms={currentTerms} checkedCategories={currentFilters.checkedCategories} onChange={this.updateFilters} /> 
                                </div>
                        </PanelRow>
                    </PanelBody>
                </Panel>
            )}
        </>
        )
    }
}