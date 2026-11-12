/**
 * admin-ajax client. POSTs FormData with `action` + `_ajax_nonce`. wp_send_json_success
 * payloads are unwrapped to `data`; raw wp_send_json responses (scan actions) pass through.
 */
export function createApi({ ajaxUrl, nonce }) {
    return async function call(action, data = {}) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('_ajax_nonce', nonce);
        Object.entries(data).forEach(([k, v]) => {
            fd.append(k, v !== null && typeof v === 'object' && !(v instanceof Blob) ? JSON.stringify(v) : v);
        });
        let json = null;
        try {
            const res = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
            json = await res.json();
        } catch (e) {
            throw new Error('network');
        }
        if (!json || json.success === false) {
            throw new Error((json && json.data && json.data.message) || 'error');
        }
        return 'success' in json ? json.data : json;
    };
}
