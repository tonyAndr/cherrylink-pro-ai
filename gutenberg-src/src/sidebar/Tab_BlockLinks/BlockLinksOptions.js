import {HorizontalRule, CheckboxControl} from '@wordpress/components';

export const BlockLinksOptions = (props) => (
    <>
        <h2>Опции блока ссылок</h2>
        <CheckboxControl
            label="Разрешить вывод блоков ссылок?"
            help="Индивидуальные настройки отображения для данной записи"
            checked={props.showAfter}
            onChange={props.updateShowAfter}
        />
        <HorizontalRule/>
        <CheckboxControl
            label="Включить редактор анкоров"
            help="Позволяет вручную изменить анкоры ссылок в блоке для каждой ссылки отдельно"
            checked={props.manualEdit}
            onChange={props.updateManualEdit}
        />
    </>
)