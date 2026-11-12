import { __ } from '@wordpress/i18n';

export class ApiError extends Error {
    constructor(code, message) {
        super(message);
        this.code = code;
    }
}

/**
 * JSON-over-admin-ajax client. Every action posts a JSON body and resolves to the
 * response object; `{ ok:false, error, message }` and network failures reject with ApiError.
 */
export function createApi({ ajaxUrl, nonce }) {
    return async function call(action, body = {}) {
        const url = `${ajaxUrl}?action=${encodeURIComponent(action)}&_ajax_nonce=${encodeURIComponent(nonce)}`;
        let res;
        try {
            res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
        } catch (e) {
            throw new ApiError('network', __('No connection to the server', 'cherrylink'));
        }
        let data = null;
        try {
            data = await res.json();
        } catch (e) {
            data = null;
        }
        if (!data || typeof data !== 'object' || data.ok === false || !res.ok) {
            const code = (data && data.error) || `http_${res.status}`;
            const message = (data && data.message) || __('Could not load data. Try again.', 'cherrylink');
            throw new ApiError(code, message);
        }
        return data;
    };
}
