import { createContext, useContext, useEffect, useRef, useState } from '@wordpress/element';

export const Ctx = createContext(null);
export const useAdmin = () => useContext(Ctx);

/** Scan state + polling. Polls (and nudges the scheduler) every 3 s only while status is `running`. */
export function useSync() {
    const { api, state, setState } = useAdmin();
    const [sync, setSync] = useState(state.sync);
    const [error, setError] = useState('');
    const running = sync.status === 'running';

    useEffect(() => {
        if (!running) return undefined;
        let dead = false;
        let t;
        const tick = async () => {
            try {
                api('cherrylink_sync_tick').catch(() => {});
                const s = await api('cherrylink_sync_status');
                if (dead) return;
                setSync(s);
                if (s.status !== 'running') {
                    api('cherrylink_admin_state').then((st) => !dead && setState(st)).catch(() => {});
                    return;
                }
            } catch (e) { /* keep polling */ }
            if (!dead) t = setTimeout(tick, 3000);
        };
        t = setTimeout(tick, 3000);
        return () => { dead = true; clearTimeout(t); };
    }, [running]); // eslint-disable-line react-hooks/exhaustive-deps

    const run = async (action) => {
        setError('');
        try {
            const next = await api(action);
            setSync(next);
            // A started scan rebuilds a purged index: drop the "data removed" state at once.
            if (action === 'cherrylink_sync_start' && state.index_expired) setState({ ...state, index_expired: false });
        } catch (e) {
            setError(e.message);
        }
    };
    return { sync, error, start: () => run('cherrylink_sync_start'), cancel: () => run('cherrylink_sync_cancel') };
}

/** Link-health payload; if never computed, refreshes once (explicit user-visible action on first view). */
export function useLinkHealth(skip = false) {
    const { api } = useAdmin();
    const [data, setData] = useState(null);
    const [busy, setBusy] = useState(!skip);
    const [error, setError] = useState('');
    const once = useRef(false);

    const load = async (refresh) => {
        setBusy(true);
        setError('');
        try {
            let d = await api('cherrylink_admin_link_health', { refresh: refresh ? 1 : 0 });
            if (!refresh && !d.checked_at && !once.current) {
                once.current = true;
                d = await api('cherrylink_admin_link_health', { refresh: 1 });
            }
            setData(d);
        } catch (e) {
            setError(e.message);
        }
        setBusy(false);
    };
    useEffect(() => { if (!skip) load(false); }, [skip]); // eslint-disable-line react-hooks/exhaustive-deps
    return { data, busy, error, refresh: () => load(true) };
}
