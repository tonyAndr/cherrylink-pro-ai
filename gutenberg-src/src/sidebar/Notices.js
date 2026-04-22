import { dispatch } from '@wordpress/data';

export const showNotice = (message) => {
    dispatch("core/notices").createSuccessNotice(message, {type: "snackbar"})
}